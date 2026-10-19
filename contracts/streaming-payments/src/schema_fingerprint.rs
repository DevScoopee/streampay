//! Schema fingerprint: the append-only contract interface captured as a
//! deterministic, committed artifact (`schema/fingerprint.json`).
//!
//! The fingerprint is built from two independent sources so that either alone
//! cannot silently drift:
//!
//! 1. **The built Wasm spec.** Every `contractspecv0` custom section of the
//!    compiled contract (`target/wasm32v1-none/release/streampay_contract.wasm`)
//!    is decoded into `ScSpecEntry`s and rendered into a stable, human-readable
//!    signature per function, event, error enum, struct, enum and union. Because
//!    this comes from the *built* Wasm it is exactly what a client of the
//!    deployed contract sees.
//! 2. **A storage-key probe.** Every [`DataKey`] / [`PolicyDataKey`] variant is
//!    instantiated and serialized to its `ScVal` XDR. Storage keys are internal
//!    and do not appear in the declarative spec, so this is the only place their
//!    on-chain encoding is pinned.
//!
//! See `schema/README.md` for what the fingerprint protects, how to bless a
//! deliberate change, and the migration-analysis requirement for a version bump.

#![cfg(test)]

use std::io::Cursor;
use std::path::PathBuf;
use std::string::String;
use std::vec::Vec;

use soroban_sdk::xdr::{
    AccountId, ContractId, Hash, Limits, Limited, PublicKey, ReadXdr, ScAddress, ScSpecEntry,
    ScSpecEventDataFormat, ScSpecEventParamLocationV0, ScSpecEventV0, ScSpecFunctionV0, ScSpecTypeDef,
    ScSpecUdtEnumV0, ScSpecUdtErrorEnumV0, ScSpecUdtStructV0, ScSpecUdtUnionCaseV0, ScSpecUdtUnionV0,
    ScVal, StringM, Uint256, WriteXdr,
};
use soroban_sdk::{Address, Env, FromVal, IntoVal, TryFromVal, Val};

use crate::policy::PolicyDataKey;
use crate::stream::DataKey;
use crate::StreamError;

/// WebAssembly module magic, spelled as bytes to keep the source free of
/// control characters.
const WASM_MAGIC: [u8; 4] = [0x00, 0x61, 0x73, 0x6d];

/// The committed fingerprint this test guards.
fn fingerprint_path() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../../schema/fingerprint.json")
}

/// Locates the built Wasm module. `STREAMPAY_WASM` overrides the default
/// workspace path; if neither exists the check is skipped (the CI step always
/// builds the Wasm first).
fn wasm_path() -> Option<PathBuf> {
    if let Ok(configured) = std::env::var("STREAMPAY_WASM") {
        let path = PathBuf::from(configured);
        return path.exists().then_some(path);
    }
    let default = PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("../../target/wasm32v1-none/release/streampay_contract.wasm");
    default.exists().then_some(default)
}

// ---------------------------------------------------------------------------
// Rendering helpers
// ---------------------------------------------------------------------------

/// UTF-8 text of a bounded XDR string or symbol.
fn text<const N: u32>(value: &StringM<N>) -> String {
    value.to_utf8_string().unwrap_or_else(|_| String::from("<non-utf8>"))
}

fn hex(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut out = String::with_capacity(bytes.len() * 2);
    for &byte in bytes {
        out.push(HEX[(byte >> 4) as usize] as char);
        out.push(HEX[(byte & 0x0f) as usize] as char);
    }
    out
}

/// Canonical XDR hex of the `ScVal` a value serializes to.
fn scval_hex(env: &Env, value: &Val) -> String {
    hex(&ScVal::from_val(env, value).to_xdr(Limits::none()).expect("scval xdr"))
}

fn val_hex<T>(env: &Env, value: &T) -> String
where
    T: IntoVal<Env, Val>,
{
    scval_hex(env, &value.into_val(env))
}

/// Deterministic account address from a single seed byte.
fn account(env: &Env, seed: u8) -> Address {
    let sc = ScAddress::Account(AccountId(PublicKey::PublicKeyTypeEd25519(Uint256([seed; 32]))));
    Address::try_from_val(env, &sc).expect("account address")
}

/// Deterministic contract address from a single seed byte.
fn contract(env: &Env, seed: u8) -> Address {
    let sc = ScAddress::Contract(ContractId(Hash([seed; 32])));
    Address::try_from_val(env, &sc).expect("contract address")
}

fn json_string(value: &str) -> String {
    let mut out = String::with_capacity(value.len() + 2);
    out.push('"');
    for c in value.chars() {
        match c {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            c if (c as u32) < 0x20 => out.push_str(&std::format!("\\u{:04x}", c as u32)),
            c => out.push(c),
        }
    }
    out.push('"');
    out
}

fn type_str(t: &ScSpecTypeDef) -> String {
    match t {
        ScSpecTypeDef::Val => "val".into(),
        ScSpecTypeDef::Bool => "bool".into(),
        ScSpecTypeDef::Void => "void".into(),
        ScSpecTypeDef::Error => "error".into(),
        ScSpecTypeDef::U32 => "u32".into(),
        ScSpecTypeDef::I32 => "i32".into(),
        ScSpecTypeDef::U64 => "u64".into(),
        ScSpecTypeDef::I64 => "i64".into(),
        ScSpecTypeDef::Timepoint => "timepoint".into(),
        ScSpecTypeDef::Duration => "duration".into(),
        ScSpecTypeDef::U128 => "u128".into(),
        ScSpecTypeDef::I128 => "i128".into(),
        ScSpecTypeDef::U256 => "u256".into(),
        ScSpecTypeDef::I256 => "i256".into(),
        ScSpecTypeDef::Bytes => "bytes".into(),
        ScSpecTypeDef::String => "string".into(),
        ScSpecTypeDef::Symbol => "symbol".into(),
        ScSpecTypeDef::Address => "address".into(),
        ScSpecTypeDef::MuxedAddress => "muxed_address".into(),
        ScSpecTypeDef::Option(v) => std::format!("option<{}>", type_str(&v.value_type)),
        ScSpecTypeDef::Result(v) => {
            std::format!("result<{},{}>", type_str(&v.ok_type), type_str(&v.error_type))
        }
        ScSpecTypeDef::Vec(v) => std::format!("vec<{}>", type_str(&v.element_type)),
        ScSpecTypeDef::Map(v) => {
            std::format!("map<{},{}>", type_str(&v.key_type), type_str(&v.value_type))
        }
        ScSpecTypeDef::Tuple(v) => {
            let parts: Vec<String> = v.value_types.iter().map(type_str).collect();
            std::format!("tuple<{}>", parts.join(", "))
        }
        ScSpecTypeDef::BytesN(v) => std::format!("bytes<{}>", v.n),
        ScSpecTypeDef::Udt(v) => text(&v.name),
    }
}

fn function_signature(f: &ScSpecFunctionV0) -> String {
    let inputs: Vec<String> = f
        .inputs
        .iter()
        .map(|i| std::format!("{}:{}", text(&i.name), type_str(&i.type_)))
        .collect();
    let outputs: Vec<String> = f.outputs.iter().map(type_str).collect();
    let ret = if outputs.is_empty() { String::from("()") } else { outputs.join(", ") };
    std::format!("{}({}) -> {}", text(&f.name.0), inputs.join(", "), ret)
}

fn struct_signature(u: &ScSpecUdtStructV0) -> String {
    let fields: Vec<String> = u
        .fields
        .iter()
        .map(|f| std::format!("{}:{}", text(&f.name), type_str(&f.type_)))
        .collect();
    std::format!("struct {} {{ {} }}", text(&u.name), fields.join(", "))
}

fn union_case(case: &ScSpecUdtUnionCaseV0) -> String {
    match case {
        ScSpecUdtUnionCaseV0::VoidV0(v) => text(&v.name),
        ScSpecUdtUnionCaseV0::TupleV0(v) => {
            let types: Vec<String> = v.type_.iter().map(type_str).collect();
            std::format!("{}({})", text(&v.name), types.join(", "))
        }
    }
}

fn union_signature(u: &ScSpecUdtUnionV0) -> String {
    let cases: Vec<String> = u.cases.iter().map(union_case).collect();
    std::format!("union {} {{ {} }}", text(&u.name), cases.join(", "))
}

fn enum_signature(u: &ScSpecUdtEnumV0) -> String {
    let cases: Vec<String> = u
        .cases
        .iter()
        .map(|c| std::format!("{}={}", text(&c.name), c.value))
        .collect();
    std::format!("enum {} {{ {} }}", text(&u.name), cases.join(", "))
}

fn event_signature(e: &ScSpecEventV0) -> String {
    let prefixes: Vec<String> = e.prefix_topics.iter().map(|s| text(&s.0)).collect();
    let topics: Vec<String> = e
        .params
        .iter()
        .filter(|p| matches!(p.location, ScSpecEventParamLocationV0::TopicList))
        .map(|p| std::format!("{}:{}", text(&p.name), type_str(&p.type_)))
        .collect();
    let data: Vec<String> = e
        .params
        .iter()
        .filter(|p| matches!(p.location, ScSpecEventParamLocationV0::Data))
        .map(|p| std::format!("{}:{}", text(&p.name), type_str(&p.type_)))
        .collect();
    let data_format = match e.data_format {
        ScSpecEventDataFormat::SingleValue => "single",
        ScSpecEventDataFormat::Vec => "vec",
        ScSpecEventDataFormat::Map => "map",
    };
    std::format!(
        "{}: prefixes=[{}] topics=[{}] data={{{}}} format={}",
        text(&e.name.0),
        prefixes.join(", "),
        topics.join(", "),
        data.join(", "),
        data_format
    )
}

// ---------------------------------------------------------------------------
// Wasm custom-section extraction
// ---------------------------------------------------------------------------

/// Reads an unsigned LEB128 integer, returning `(value, next_offset)`.
fn read_uleb(bytes: &[u8], mut pos: usize) -> (usize, usize) {
    let mut result = 0usize;
    let mut shift = 0u32;
    loop {
        let byte = bytes[pos];
        pos += 1;
        result |= ((byte & 0x7f) as usize) << shift;
        if byte & 0x80 == 0 {
            break;
        }
        shift += 7;
    }
    (result, pos)
}

/// Returns the payload of every custom section named `name`.
fn custom_sections(wasm: &[u8], name: &str) -> Vec<Vec<u8>> {
    assert!(wasm.len() >= 8 && wasm[..4] == WASM_MAGIC, "not a Wasm module");
    let mut pos = 8usize;
    let mut found = Vec::new();
    while pos < wasm.len() {
        let id = wasm[pos];
        pos += 1;
        let (size, body_start) = read_uleb(wasm, pos);
        let body_end = body_start + size;
        assert!(body_end <= wasm.len(), "truncated Wasm section");
        if id == 0 {
            let (name_len, name_start) = read_uleb(wasm, body_start);
            let name_end = name_start + name_len;
            if &wasm[name_start..name_end] == name.as_bytes() {
                found.push(wasm[name_end..body_end].to_vec());
            }
        }
        pos = body_end;
    }
    found
}

/// Decodes every `ScSpecEntry` embedded in the built Wasm's `contractspecv0`
/// custom sections.
fn spec_entries(wasm: &[u8]) -> Vec<ScSpecEntry> {
    let mut entries = Vec::new();
    for body in custom_sections(wasm, "contractspecv0") {
        let mut limited = Limited::new(Cursor::new(body), Limits::none());
        for entry in ScSpecEntry::read_xdr_iter(&mut limited) {
            entries.push(entry.expect("contractspecv0 entry is valid XDR"));
        }
    }
    entries
}

// ---------------------------------------------------------------------------
// Storage-key probes
// ---------------------------------------------------------------------------

/// Every [`DataKey`] variant, in declaration order. Adding a variant is a
/// compile error in [`data_key_name`] until it is listed here too.
fn data_keys(env: &Env) -> Vec<DataKey> {
    let a = account(env, 7);
    std::vec![
        DataKey::NextStreamId,
        DataKey::Stream(1),
        DataKey::RecipientStreams(a.clone()),
        DataKey::SenderStreams(a.clone()),
        DataKey::NextIntentId,
        DataKey::Intent(1),
        DataKey::SenderIntents(a.clone()),
        DataKey::Governor,
        DataKey::PendingGovernor,
        DataKey::FeeBps,
        DataKey::Treasury(a.clone()),
        DataKey::StreamFee(1),
        DataKey::SchemaVersion,
        DataKey::ContractFrozen,
        DataKey::PendingUpgrade,
        DataKey::MigrationCursor,
    ]
}

/// Exhaustive over [`DataKey`] (no wildcard): a new variant fails to compile
/// until its name is listed, which also keeps [`data_keys`] honest.
fn data_key_name(key: &DataKey) -> &'static str {
    match key {
        DataKey::NextStreamId => "NextStreamId",
        DataKey::Stream(_) => "Stream",
        DataKey::RecipientStreams(_) => "RecipientStreams",
        DataKey::SenderStreams(_) => "SenderStreams",
        DataKey::NextIntentId => "NextIntentId",
        DataKey::Intent(_) => "Intent",
        DataKey::SenderIntents(_) => "SenderIntents",
        DataKey::Governor => "Governor",
        DataKey::PendingGovernor => "PendingGovernor",
        DataKey::FeeBps => "FeeBps",
        DataKey::Treasury(_) => "Treasury",
        DataKey::StreamFee(_) => "StreamFee",
        DataKey::SchemaVersion => "SchemaVersion",
        DataKey::ContractFrozen => "ContractFrozen",
        DataKey::PendingUpgrade => "PendingUpgrade",
        DataKey::MigrationCursor => "MigrationCursor",
    }
}

/// Every [`PolicyDataKey`] variant, in declaration order.
fn policy_data_keys(env: &Env) -> Vec<PolicyDataKey> {
    let a = contract(env, 9);
    std::vec![
        PolicyDataKey::Admin,
        PolicyDataKey::PendingAdmin,
        PolicyDataKey::TimelockDelay,
        PolicyDataKey::Asset(a.clone()),
        PolicyDataKey::QueuedAsset(a.clone()),
        PolicyDataKey::StreamPolicy(a.clone()),
        PolicyDataKey::GlobalStreamPolicy,
    ]
}

/// Exhaustive over [`PolicyDataKey`] (no wildcard).
fn policy_data_key_name(key: &PolicyDataKey) -> &'static str {
    match key {
        PolicyDataKey::Admin => "Admin",
        PolicyDataKey::PendingAdmin => "PendingAdmin",
        PolicyDataKey::TimelockDelay => "TimelockDelay",
        PolicyDataKey::Asset(_) => "Asset",
        PolicyDataKey::QueuedAsset(_) => "QueuedAsset",
        PolicyDataKey::StreamPolicy(_) => "StreamPolicy",
        PolicyDataKey::GlobalStreamPolicy => "GlobalStreamPolicy",
    }
}

// ---------------------------------------------------------------------------
// Fingerprint assembly
// ---------------------------------------------------------------------------

fn json_array(out: &mut String, key: &str, values: &[String]) {
    out.push_str(&std::format!("  \"{key}\": [\n"));
    for (i, value) in values.iter().enumerate() {
        let comma = if i + 1 == values.len() { "" } else { "," };
        out.push_str(&std::format!("    {}{comma}\n", json_string(value)));
    }
    out.push_str("  ],\n");
}

fn build_fingerprint() -> Option<String> {
    let wasm_file = wasm_path()?;
    let wasm = std::fs::read(&wasm_file).expect("read Wasm module");
    let env = Env::default();
    let entries = spec_entries(&wasm);

    let mut interface: Vec<String> = Vec::new();
    let mut storage_values: Vec<String> = Vec::new();
    let mut events: Vec<String> = Vec::new();
    let mut spec_errors: Vec<(u32, String)> = Vec::new();

    for entry in &entries {
        match entry {
            ScSpecEntry::FunctionV0(f) => interface.push(function_signature(f)),
            ScSpecEntry::UdtStructV0(u) => storage_values.push(struct_signature(u)),
            ScSpecEntry::UdtUnionV0(u) => storage_values.push(union_signature(u)),
            ScSpecEntry::UdtEnumV0(u) => storage_values.push(enum_signature(u)),
            ScSpecEntry::UdtErrorEnumV0(u) => {
                for case in u.cases.iter() {
                    spec_errors.push((case.value, text(&case.name)));
                }
            }
            ScSpecEntry::EventV0(e) => events.push(event_signature(e)),
        }
    }

    spec_errors.sort_by(|a, b| a.0.cmp(&b.0));
    if !spec_errors.is_empty() {
        let mut listed: Vec<u32> = StreamError::ALL.iter().map(|e| e.code()).collect();
        listed.sort_unstable();
        let mut spec_codes: Vec<u32> = spec_errors.iter().map(|(c, _)| *c).collect();
        spec_codes.sort_unstable();
        assert_eq!(
            listed, spec_codes,
            "StreamError::ALL diverges from the Wasm error enum; update one to match the other"
        );
    }

    let mut storage_keys: Vec<String> = Vec::new();
    for key in data_keys(&env) {
        storage_keys.push(std::format!(
            "DataKey::{} = {}",
            data_key_name(&key),
            val_hex(&env, &key)
        ));
    }
    for key in policy_data_keys(&env) {
        storage_keys.push(std::format!(
            "PolicyDataKey::{} = {}",
            policy_data_key_name(&key),
            val_hex(&env, &key)
        ));
    }

    interface.sort();
    storage_values.sort();
    storage_keys.sort();
    events.sort();

    let mut out = String::new();
    out.push_str("{\n");
    out.push_str("  \"format\": 1,\n");
    out.push_str("  \"version\": 1,\n");
    out.push_str(&std::format!(
        "  \"note\": {},\n",
        json_string("Append-only interface fingerprint. See schema/README.md before blessing.")
    ));
    out.push_str(&std::format!(
        "  \"spec_source\": {},\n",
        json_string("target/wasm32v1-none/release/streampay_contract.wasm")
    ));

    out.push_str("  \"errors\": [\n");
    for (i, (code, name)) in spec_errors.iter().enumerate() {
        let comma = if i + 1 == spec_errors.len() { "" } else { "," };
        out.push_str(&std::format!(
            "    {{\"code\": {code}, \"name\": {}}}{comma}\n",
            json_string(name)
        ));
    }
    out.push_str("  ],\n");

    json_array(&mut out, "storage_keys", &storage_keys);
    json_array(&mut out, "storage_values", &storage_values);
    json_array(&mut out, "events", &events);
    json_array(&mut out, "interface", &interface);

    out.push_str("}\n");
    Some(out)
}

/// A compact line diff, truncated after a screenful of hunks.
fn line_diff(old: &str, new: &str) -> String {
    let old_lines: Vec<&str> = old.lines().collect();
    let new_lines: Vec<&str> = new.lines().collect();
    let max = old_lines.len().max(new_lines.len());
    let mut out = String::new();
    let mut shown = 0usize;
    for i in 0..max {
        let before = old_lines.get(i).copied();
        let after = new_lines.get(i).copied();
        if before != after {
            out.push_str(&std::format!(
                "@@ line {}\n- {}\n+ {}\n",
                i + 1,
                before.unwrap_or("<missing>"),
                after.unwrap_or("<missing>")
            ));
            shown += 1;
            if shown >= 60 {
                out.push_str("... (diff truncated)\n");
                break;
            }
        }
    }
    out
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

/// Guards `schema/fingerprint.json`. Fails with a diff when the interface
/// drifts; bless a reviewed change with `bless_schema_fingerprint`.
#[test]
fn schema_fingerprint_is_up_to_date() {
    let Some(generated) = build_fingerprint() else {
        std::eprintln!(
            "schema_fingerprint: no Wasm module found (run `stellar contract build`); skipping"
        );
        return;
    };
    let path = fingerprint_path();
    let committed = std::fs::read_to_string(&path).unwrap_or_default();
    if committed.trim() != generated.trim() {
        std::eprintln!("{}", line_diff(&committed, &generated));
        panic!(
            "schema fingerprint is stale. Run \
             `cargo test -p streampay-contract -- --ignored bless_schema_fingerprint`, \
             review the diff in schema/fingerprint.json, and commit it."
        );
    }
}

/// Regenerates `schema/fingerprint.json` from the built Wasm. Run only after
/// reviewing a deliberate interface change (see `schema/README.md`).
#[test]
#[ignore = "writes schema/fingerprint.json"]
fn bless_schema_fingerprint() {
    let generated = build_fingerprint()
        .expect("no Wasm module found; run `stellar contract build` before blessing");
    let path = fingerprint_path();
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent).expect("create schema directory");
    }
    std::fs::write(&path, generated).expect("write fingerprint");
    std::eprintln!("schema_fingerprint: wrote {}", path.display());
}

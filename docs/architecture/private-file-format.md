# Private file format, version 1 (S3a)

The implementation is `app/Platform/Files`. S3a covers uploads, note images,
PDF previews and modern Office copies. S3b adds recording pieces, joined audio,
extracted JSON and saved transcription diagnostics. This specification follows the owner's S3 split and OpenSSL
override in the conversation; the older unsplit handoff does not override it.

## Key and identity

AES-256-GCM uses PHP's built-in OpenSSL functions with raw binary output and a
16-byte authentication tag. The files key is HKDF-SHA256, 32 bytes, from the
learner data key, with info `vistud:files:v1` and salt equal to the learner id.
The scoped learner key ring supplies it; a header never chooses a learner/key.
A missing or destroyed key fails closed. No plaintext reader fallback exists.

The trusted caller supplies learner id, object id and kind. Allowed paths are
`learners/<learner>/files/<id>`, `note-images/<id>.<extension>` and
`previews/<id>.<extension>` below that learner. The object id is the upload id,
or the independent note-image id. Kinds are `upload`, `note-image:png/jpg/webp/gif`
and `preview:pdf/docx/pptx/xlsx` (each slash-separated alternative is a distinct
kind). Extensions, learner ownership and path syntax are checked before IO.

S3b also accepts `classes/<class id>/<recording id>` (kind `recording`) and
the suffix `.chunks/<piece index>` (kind `recording-piece:<index>`). Their object
identity is `<class id>/<recording id>`, so both the class and piece position are
bound. `texts/<file id>.json` uses kind `extracted-text`; the existing JSON
schema/version is unchanged. `transcribe-try/<class id>/<run id>.transcript.md`
or `.notes.md` uses object `<class id>/<run id>` and kind
`transcription:transcript` or `transcription:notes`.

Every frame has a fresh cryptographically random 12-byte nonce. Every complete
write has a fresh random 16-byte generation. Associated data is the ASCII magic
`VSTFILE1` followed immediately by UTF-8 JSON, with no whitespace and unescaped
slashes, of this ordered array:

```text
[1, learner_id, object_id, kind, lowercase_hex_generation, chunk_index, byte_count]
```

The header uses chunk index -1 and byte count 64. Content chunks use zero-based
indices and their actual plaintext byte count. This binds version, identity,
kind, generation, position and length. Renaming an object to another identity,
mixing generations, swapping/duplicating chunks or changing bytes fails GCM.
Moving between study folders or trash changes metadata, not this identity.

## Binary layout

All offsets and lengths below are bytes; chunks are 262,144 bytes (256 KiB).

| Offset | Length | Value |
|---|---:|---|
| 0 | 8 | ASCII `VSTFILE1` |
| 8 | 16 | Random generation |
| 24 | 12 | Header nonce |
| 36 | 64 | Encrypted header payload |
| 100 | 16 | Header tag |
| 116 | variable | Content frames |

The decrypted header payload contains an unsigned 64-bit big-endian plaintext
length, a raw 32-byte SHA-256 of the complete plaintext, then 24 zero reserved
bytes. Nonzero reserved bytes, unsupported magic, impossible length and invalid
tags are refused. The current implementation requires 64-bit PHP and bounds a
file at 1 TiB; application upload limits remain much smaller.

Each content frame is nonce (12), raw ciphertext (the chunk length), tag (16).
All chunks except the final chunk are full size; an empty file has only the
header. Frame i starts at `116 + i * (262144 + 28)`. Total physical size must be
exactly `116 + plaintext_length + ceil(plaintext_length / 262144) * 28`.
Truncation and appended bytes are refused before content is returned.

Neither plaintext length nor an unkeyed content fingerprint is stored in the
readable header. Physical ciphertext size still reveals plaintext size through
the framing formula: this format does not pad files. The database upload
fingerprint is hex HMAC-SHA256 of the raw plaintext SHA-256 under the files key;
it does not permit unkeyed known-file confirmation. The raw SHA is encrypted.

Changing this layout, chunk size, AAD encoding or key derivation needs a new
version and an explicit converter, never an edit to the meaning of version 1.

## IO, verification and ranges

Writes stream one chunk at a time to an exclusive sibling staging file, seal
the header, flush/sync, reopen and verify every frame and the complete digest,
then atomically rename over the target. Failure preserves the existing target
and removes the stage. A process killed mid-write can leave an encrypted stage;
S3b's offline inventory must count and reconcile it. No delete-target fallback
is used if atomic publication fails. The disks must be private local seekable
filesystems; remote adapters are rejected rather than buffered in memory.

GET and single-range GET authenticate the header, physical length and first
selected frame before returning a successful response. The iterator retains
that frame, then streams the same open descriptor: each later frame authenticates
before yielding any bytes. A corrupt later frame ends the stream, without sending
its bytes; the Content-Length makes the incomplete response detectable. No range
is decrypted twice. Seeking reads only intersecting chunks.
Offline full verification also compares the complete digest. An altered *unrequested* chunk is
detected on full verification or when requested, not by an unrelated range.
HEAD authenticates the header and physical length, without scanning content.
Opaque ETags use the random generation. Responses are private/no-store.
Suffix/open/EOF ranges and If-Range are supported; invalid, unsatisfiable and
multiple ranges return 416. There is no plaintext HTTP staging file.

Temporary paths needed by parsers/converters belong to leased private
workspaces, closed in finally blocks or after export streaming. See
[operations.md](../development/operations.md#private-file-storage-and-temporary-bytes-s3a)
for the required production RAM-backed temp configuration. The existing capped
GD, Smalot PDF and base64 AI consumers still use memory within their old caps;
the codec, storage, verification and HTTP transport do not grow with file size.

S3b assembly feeds decrypted piece generators to the same writer, which reframes
them at 256 KiB. It never makes a plaintext joined file. Pieces are removed only
after the recording metadata commits. ffmpeg input and output are leased temp
workspaces. The offline command uses exclusive sibling `.converting-*` stages
and read-back verification before atomic replacement. Per-object conversion
receipts live under the learner's `file-conversion/` tree: raw GCM-sealed JSON,
fresh nonce, files key, AAD `vistud:file-state:v1:` followed by the canonical path.
Receipts contain the bound path, keyed fingerprint, length, target direction and
pending/completed status; their filenames are HMAC-SHA256 of the path. They are
included with canonical backups and disappear with the learner's source tree.

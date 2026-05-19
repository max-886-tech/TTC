#pragma once

#include <cstdint>
#include <string>
#include <vector>

namespace ttc::format {

// -----------------------------
// TTC constants
// -----------------------------
constexpr char TTC_MAGIC[4] = { 'T', 'T', 'C', 'F' };

// SINGLE supported version (AES-GCM + AAD)
constexpr uint16_t TTC_VERSION = 1;

// -----------------------------
// TTC header (packed)
// -----------------------------
#pragma pack(push, 1)
struct TtcHeader {
    char     magic[4];        // "TTCF"
    uint16_t version;         // always TTC_VERSION
    uint16_t flags;
    uint8_t  salt[16];
    uint8_t  nonce[12];
    uint32_t metadataSize;
    uint64_t payloadSize;
};
#pragma pack(pop)

// -----------------------------
// Metadata
// -----------------------------
struct TtcMetadata {
    std::string examId;
    std::string user;
    std::string expiryAbsolute;
    uint32_t    expiryRelativeHrs;
    std::vector<std::string> watermarkLines;
};

// -----------------------------
// Full TTC container
// -----------------------------
struct TtcFile {
    TtcHeader header;
    TtcMetadata metadata;
    std::vector<uint8_t> metadataJsonRaw;
    std::vector<uint8_t> encryptedPayload;
    uint8_t authTag[16];
};

// -----------------------------
// API
// -----------------------------
std::vector<uint8_t> SerializeMetadata(const TtcMetadata& meta);

bool ParseMetadata(
    const std::vector<uint8_t>& jsonBytes,
    TtcMetadata& outMeta
);

bool WriteTtcFile(
    const std::string& path,
    const TtcHeader& header,
    const std::vector<uint8_t>& metadataJson,
    const std::vector<uint8_t>& encryptedPayload,
    const uint8_t* authTag
);

bool ReadTtcFile(
    const std::string& path,
    TtcFile& outFile
);

// Windows-safe wide-path overload (avoids ACP/UTF-8 issues on non-English usernames)
bool ReadTtcFileW(
    const std::wstring& path,
    TtcFile& outFile
);

} // namespace ttc::format

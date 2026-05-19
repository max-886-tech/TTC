#include "ttc_format.h"

#include <fstream>
#include <cstring>
#include <filesystem>
#include <nlohmann/json.hpp>

using json = nlohmann::json;

namespace ttc::format {

// Hard limits to prevent crash / DoS
static constexpr uint32_t MAX_METADATA_SIZE = 64u * 1024u;                // 64 KB
static constexpr uint64_t MAX_PAYLOAD_SIZE  = 500ull * 1024ull * 1024ull; // 500 MB

// -------------------------------------------------
// Metadata → JSON
// -------------------------------------------------
std::vector<uint8_t> SerializeMetadata(const TtcMetadata& meta) {
    json j;
    j["examId"] = meta.examId;
    j["user"] = meta.user;
    j["expiryAbs"] = meta.expiryAbsolute;
    j["expiryRelHours"] = meta.expiryRelativeHrs;
    j["watermark"] = meta.watermarkLines;

    std::string s = j.dump();
    return std::vector<uint8_t>(s.begin(), s.end());
}

// -------------------------------------------------
// JSON → Metadata
// -------------------------------------------------
bool ParseMetadata(
    const std::vector<uint8_t>& jsonBytes,
    TtcMetadata& outMeta
) {
    try {
        json j = json::parse(
            std::string(jsonBytes.begin(), jsonBytes.end())
        );

        outMeta.examId            = j.value("examId", "");
        outMeta.user              = j.value("user", "");
        outMeta.expiryAbsolute    = j.value("expiryAbs", "");
        outMeta.expiryRelativeHrs = j.value("expiryRelHours", 0);
        outMeta.watermarkLines =
            j.value("watermark", std::vector<std::string>{});

        return true;
    }
    catch (...) {
        return false;
    }
}

// -------------------------------------------------
// Write TTC file
// -------------------------------------------------
bool WriteTtcFile(
    const std::string& path,
    const TtcHeader& header,
    const std::vector<uint8_t>& metadataJson,
    const std::vector<uint8_t>& encryptedPayload,
    const uint8_t* authTag
) {
    std::ofstream out(path, std::ios::binary);
    if (!out)
        return false;

    out.write(reinterpret_cast<const char*>(&header), sizeof(header));
    out.write(reinterpret_cast<const char*>(metadataJson.data()),
              metadataJson.size());
    out.write(reinterpret_cast<const char*>(encryptedPayload.data()),
              encryptedPayload.size());
    out.write(reinterpret_cast<const char*>(authTag), 16);

    return out.good();
}

// -------------------------------------------------
// Read TTC file
// -------------------------------------------------
bool ReadTtcFile(
    const std::string& path,
    TtcFile& outFile
) {
    std::error_code ec;
    const uint64_t fileSize = std::filesystem::file_size(path, ec);
    if (ec)
        return false;

    std::ifstream in(path, std::ios::binary);
    if (!in)
        return false;

    // Header
    in.read(reinterpret_cast<char*>(&outFile.header), sizeof(TtcHeader));
    if (!in)
        return false;

    // Magic
    if (std::memcmp(outFile.header.magic, TTC_MAGIC, 4) != 0)
        return false;

    // Version (single supported)
    if (outFile.header.version != TTC_VERSION)
        return false;

    // Size checks
    if (outFile.header.metadataSize == 0 ||
        outFile.header.metadataSize > MAX_METADATA_SIZE)
        return false;

    if (outFile.header.payloadSize == 0 ||
        outFile.header.payloadSize > MAX_PAYLOAD_SIZE)
        return false;

    // Exact file size validation
    const uint64_t expected =
        sizeof(TtcHeader) +
        outFile.header.metadataSize +
        outFile.header.payloadSize +
        16ull;

    if (expected != fileSize)
        return false;

    // Metadata JSON (raw)
    outFile.metadataJsonRaw.resize(outFile.header.metadataSize);
    in.read(reinterpret_cast<char*>(outFile.metadataJsonRaw.data()),
            outFile.metadataJsonRaw.size());
    if (!in)
        return false;

    if (!ParseMetadata(outFile.metadataJsonRaw, outFile.metadata))
        return false;

    // Encrypted payload
    outFile.encryptedPayload.resize(outFile.header.payloadSize);
    in.read(reinterpret_cast<char*>(outFile.encryptedPayload.data()),
            outFile.encryptedPayload.size());
    if (!in)
        return false;

    // Auth tag
    in.read(reinterpret_cast<char*>(outFile.authTag), 16);
    if (!in)
        return false;

    return true;
}


// -------------------------------------------------
// Read TTC file (wide path overload)
// -------------------------------------------------
bool ReadTtcFileW(
    const std::wstring& path,
    TtcFile& outFile
) {
    // Use filesystem::path so Windows wide paths work correctly.
    std::filesystem::path p(path);

    std::error_code ec;
    const uint64_t fileSize = std::filesystem::file_size(p, ec);
    if (ec)
        return false;

    std::ifstream in(p, std::ios::binary);
    if (!in)
        return false;

    // Header
    in.read(reinterpret_cast<char*>(&outFile.header), sizeof(TtcHeader));
    if (!in)
        return false;

    // Magic
    if (std::memcmp(outFile.header.magic, TTC_MAGIC, 4) != 0)
        return false;

    // Version (single supported)
    if (outFile.header.version != TTC_VERSION)
        return false;

    // Size checks
    if (outFile.header.metadataSize == 0 ||
        outFile.header.metadataSize > MAX_METADATA_SIZE)
        return false;

    if (outFile.header.payloadSize == 0 ||
        outFile.header.payloadSize > MAX_PAYLOAD_SIZE)
        return false;

    // Exact file size validation
    const uint64_t expected =
        sizeof(TtcHeader) +
        outFile.header.metadataSize +
        outFile.header.payloadSize +
        16ull;

    if (expected != fileSize)
        return false;

    // Metadata JSON (raw)
    outFile.metadataJsonRaw.resize(outFile.header.metadataSize);
    in.read(reinterpret_cast<char*>(outFile.metadataJsonRaw.data()),
            outFile.metadataJsonRaw.size());
    if (!in)
        return false;

    if (!ParseMetadata(outFile.metadataJsonRaw, outFile.metadata))
        return false;

    // Encrypted payload
    outFile.encryptedPayload.resize(outFile.header.payloadSize);
    in.read(reinterpret_cast<char*>(outFile.encryptedPayload.data()),
            outFile.encryptedPayload.size());
    if (!in)
        return false;

    // Auth tag
    in.read(reinterpret_cast<char*>(outFile.authTag), 16);
    if (!in)
        return false;

    return true;
}

} // namespace ttc::format

#include "ttc_decrypt.h"

#include <cstring>

#ifdef _WIN32
#  include <windows.h> // SecureZeroMemory
#endif

#include "crypto.h"

namespace ttc::decrypt {

static void SetErr(std::string* outError, const char* msg) {
    if (outError) *outError = msg ? msg : "";
}

bool DecryptTtcToPdfBytes(
    const ttc::format::TtcFile& file,
    const std::string& password,
    std::vector<uint8_t>& outPdf,
    std::string* outError
) {
    outPdf.clear();

    // Build AAD = [raw header bytes][raw metadata JSON bytes]
    std::vector<uint8_t> aad(sizeof(ttc::format::TtcHeader) + file.metadataJsonRaw.size());
    std::memcpy(aad.data(), &file.header, sizeof(ttc::format::TtcHeader));
    if (!file.metadataJsonRaw.empty()) {
        std::memcpy(aad.data() + sizeof(ttc::format::TtcHeader),
                    file.metadataJsonRaw.data(),
                    file.metadataJsonRaw.size());
    }

    uint8_t key[ttc::crypto::KEY_LEN];
    if (!ttc::crypto::DeriveKeyPBKDF2(password, file.header.salt, key)) {
        SecureZeroMemory(key, sizeof(key));
        SetErr(outError, "Key derivation failed");
        return false;
    }

    const bool ok = ttc::crypto::DecryptAESGCM_AAD(
        file.encryptedPayload,
        key,
        file.header.nonce,
        aad,
        file.authTag,
        outPdf
    );

    SecureZeroMemory(key, sizeof(key));

    if (!ok) {
        outPdf.clear();
        SetErr(outError, "Wrong password or tampered file");
        return false;
    }

    return true;
}

bool ReadAndDecryptTtcToPdfBytes(
    const std::string& ttcPath,
    const std::string& password,
    ttc::format::TtcMetadata& outMeta,
    std::vector<uint8_t>& outPdf,
    std::string* outError
) {
    ttc::format::TtcFile file;
    if (!ttc::format::ReadTtcFile(ttcPath, file)) {
        SetErr(outError, "Invalid TTC file");
        return false;
    }
    outMeta = file.metadata;
    return DecryptTtcToPdfBytes(file, password, outPdf, outError);
}

} // namespace ttc::decrypt

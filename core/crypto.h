#pragma once
#include <cstdint>
#include <vector>
#include <string>

namespace ttc::crypto {

constexpr int SALT_LEN  = 16;
constexpr int NONCE_LEN = 12;
constexpr int KEY_LEN   = 32;
constexpr int TAG_LEN   = 16;

// RNG
bool SecureRandom(uint8_t* buffer, size_t length);

// PBKDF2-HMAC-SHA256
bool DeriveKeyPBKDF2(
    const std::string& password,
    const uint8_t* salt,
    uint8_t* outKey
);

// AES-256-GCM with AAD
bool EncryptAESGCM_AAD(
    const std::vector<uint8_t>& plaintext,
    const uint8_t* key,
    const uint8_t* nonce,
    const std::vector<uint8_t>& aad,
    std::vector<uint8_t>& ciphertext,
    uint8_t* outTag
);

bool DecryptAESGCM_AAD(
    const std::vector<uint8_t>& ciphertext,
    const uint8_t* key,
    const uint8_t* nonce,
    const std::vector<uint8_t>& aad,
    const uint8_t* tag,
    std::vector<uint8_t>& plaintext
);

} // namespace ttc::crypto

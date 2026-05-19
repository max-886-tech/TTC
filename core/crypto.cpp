#include "crypto.h"

#include <windows.h>
#include <bcrypt.h>
#include <vector>
#include <cstring>

#pragma comment(lib, "bcrypt.lib")

namespace ttc::crypto {

// -------------------------------------------------
// Secure RNG (Windows CNG)
// -------------------------------------------------
bool SecureRandom(uint8_t* buffer, size_t length) {
    return BCryptGenRandom(
        nullptr,
        buffer,
        (ULONG)length,
        BCRYPT_USE_SYSTEM_PREFERRED_RNG
    ) == 0;
}

// -------------------------------------------------
// PBKDF2-HMAC-SHA256 (Windows CNG)
// IMPORTANT: Must open SHA256 with HMAC flag
// -------------------------------------------------
bool DeriveKeyPBKDF2(
    const std::string& password,
    const uint8_t* salt,
    uint8_t* outKey
) {
    BCRYPT_ALG_HANDLE hAlg = nullptr;
    NTSTATUS status = 0;

    status = BCryptOpenAlgorithmProvider(
        &hAlg,
        BCRYPT_SHA256_ALGORITHM,
        nullptr,
        BCRYPT_ALG_HANDLE_HMAC_FLAG   // ✅ REQUIRED for PBKDF2
    );
    if (status != 0)
        return false;

    status = BCryptDeriveKeyPBKDF2(
        hAlg,
        (PUCHAR)password.data(),
        (ULONG)password.size(),
        (PUCHAR)salt,
        SALT_LEN,
        100000,                       // iterations
        outKey,
        KEY_LEN,
        0
    );

    BCryptCloseAlgorithmProvider(hAlg, 0);
    return status == 0;
}

// -------------------------------------------------
// AES-256-GCM (Encrypt)
// -------------------------------------------------
bool EncryptAESGCM_AAD(
    const std::vector<uint8_t>& plaintext,
    const uint8_t* key,
    const uint8_t* nonce,
    const std::vector<uint8_t>& aad,
    std::vector<uint8_t>& ciphertext,
    uint8_t* outTag
) {
    BCRYPT_ALG_HANDLE hAlg = nullptr;
    BCRYPT_KEY_HANDLE hKey = nullptr;
    NTSTATUS status = 0;

    ciphertext.resize(plaintext.size());

    BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO authInfo;
    BCRYPT_INIT_AUTH_MODE_INFO(authInfo);

    authInfo.pbNonce    = (PUCHAR)nonce;
    authInfo.cbNonce    = NONCE_LEN;
    authInfo.pbAuthData = aad.empty() ? nullptr : (PUCHAR)aad.data();
    authInfo.cbAuthData = (ULONG)aad.size();
    authInfo.pbTag      = outTag;
    authInfo.cbTag      = TAG_LEN;

    status = BCryptOpenAlgorithmProvider(
        &hAlg,
        BCRYPT_AES_ALGORITHM,
        nullptr,
        0
    );
    if (status != 0) goto cleanup;

    status = BCryptSetProperty(
        hAlg,
        BCRYPT_CHAINING_MODE,
        (PUCHAR)BCRYPT_CHAIN_MODE_GCM,
        sizeof(BCRYPT_CHAIN_MODE_GCM),
        0
    );
    if (status != 0) goto cleanup;

    status = BCryptGenerateSymmetricKey(
        hAlg,
        &hKey,
        nullptr,
        0,
        (PUCHAR)key,
        KEY_LEN,
        0
    );
    if (status != 0) goto cleanup;

    ULONG outLen = 0;
    status = BCryptEncrypt(
        hKey,
        (PUCHAR)plaintext.data(),
        (ULONG)plaintext.size(),
        &authInfo,
        nullptr,
        0,
        ciphertext.data(),
        (ULONG)ciphertext.size(),
        &outLen,
        0
    );

cleanup:
    if (hKey) BCryptDestroyKey(hKey);
    if (hAlg) BCryptCloseAlgorithmProvider(hAlg, 0);

    // AES-GCM keeps ciphertext same size as plaintext, but we still trust outLen
    if (status != 0) {
        ciphertext.clear();
        return false;
    }
    ciphertext.resize(outLen);
    return true;
}

// -------------------------------------------------
// AES-256-GCM (Decrypt)
// -------------------------------------------------
bool DecryptAESGCM_AAD(
    const std::vector<uint8_t>& ciphertext,
    const uint8_t* key,
    const uint8_t* nonce,
    const std::vector<uint8_t>& aad,
    const uint8_t* tag,
    std::vector<uint8_t>& plaintext
) {
    BCRYPT_ALG_HANDLE hAlg = nullptr;
    BCRYPT_KEY_HANDLE hKey = nullptr;
    NTSTATUS status = 0;

    plaintext.resize(ciphertext.size());

    BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO authInfo;
    BCRYPT_INIT_AUTH_MODE_INFO(authInfo);

    authInfo.pbNonce    = (PUCHAR)nonce;
    authInfo.cbNonce    = NONCE_LEN;
    authInfo.pbAuthData = aad.empty() ? nullptr : (PUCHAR)aad.data();
    authInfo.cbAuthData = (ULONG)aad.size();
    authInfo.pbTag      = (PUCHAR)tag;
    authInfo.cbTag      = TAG_LEN;

    status = BCryptOpenAlgorithmProvider(
        &hAlg,
        BCRYPT_AES_ALGORITHM,
        nullptr,
        0
    );
    if (status != 0) goto cleanup;

    status = BCryptSetProperty(
        hAlg,
        BCRYPT_CHAINING_MODE,
        (PUCHAR)BCRYPT_CHAIN_MODE_GCM,
        sizeof(BCRYPT_CHAIN_MODE_GCM),
        0
    );
    if (status != 0) goto cleanup;

    status = BCryptGenerateSymmetricKey(
        hAlg,
        &hKey,
        nullptr,
        0,
        (PUCHAR)key,
        KEY_LEN,
        0
    );
    if (status != 0) goto cleanup;

    ULONG outLen = 0;
    status = BCryptDecrypt(
        hKey,
        (PUCHAR)ciphertext.data(),
        (ULONG)ciphertext.size(),
        &authInfo,
        nullptr,
        0,
        plaintext.data(),
        (ULONG)plaintext.size(),
        &outLen,
        0
    );

cleanup:
    if (hKey) BCryptDestroyKey(hKey);
    if (hAlg) BCryptCloseAlgorithmProvider(hAlg, 0);

    if (status != 0) {
        plaintext.clear();
        return false;
    }

    plaintext.resize(outLen);
    return true;
}

} // namespace ttc::crypto

#pragma once

#include <string>
#include <vector>

#include "ttc_format.h"

namespace ttc::decrypt {

// Decrypts TTC payload into a PDF byte buffer.
//
// Security model:
// - Uses AES-256-GCM with AAD = [raw TtcHeader bytes][raw metadata JSON bytes]
// - The returned PDF buffer must remain alive as long as the PDFium document is open.
//
// Returns true on success. On failure, outError (if non-null) contains a short reason.
bool DecryptTtcToPdfBytes(
    const ttc::format::TtcFile& file,
    const std::string& password,
    std::vector<uint8_t>& outPdf,
    std::string* outError = nullptr
);

// Convenience wrapper: reads TTC from disk then decrypts to PDF bytes.
bool ReadAndDecryptTtcToPdfBytes(
    const std::string& ttcPath,
    const std::string& password,
    ttc::format::TtcMetadata& outMeta,
    std::vector<uint8_t>& outPdf,
    std::string* outError = nullptr
);

} // namespace ttc::decrypt

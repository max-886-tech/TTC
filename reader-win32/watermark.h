#pragma once
#include <string>

// Avoid including <windows.h> here (prevents Winsock include-order issues)
struct HDC__;
typedef HDC__* HDC;

struct tagRECT;
typedef tagRECT RECT;

namespace ttc::reader {

// Build watermark text automatically (anti-share).
// dumpName/dumpId can be empty; userTag/orderId optional.
std::wstring BuildAntiShareWatermark(
    const std::wstring& dumpName,
    const std::wstring& dumpId,
    const std::wstring& userTag = L"",
    const std::wstring& orderId = L""
);

// Old API (kept)
void DrawWatermark(HDC hdc, const RECT& rc, const std::wstring& text);

// New API (use this in viewer: pass scrollY)
void DrawWatermark(HDC hdc, const RECT& rc, const std::wstring& text, int scrollY);

} // namespace ttc::reader

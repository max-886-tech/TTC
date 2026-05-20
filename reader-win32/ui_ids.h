#pragma once

// Startup / Download UI (single-window flow)
inline constexpr int IDC_START_PANEL      = 2100;
inline constexpr int IDC_DL_PANEL         = 2101;
inline constexpr int IDC_CODE_EDIT        = 2102;
inline constexpr int IDC_BTN_CONTINUE     = 2103;
inline constexpr int IDC_BTN_CANCEL_DL    = 2104; // (unused - removed from UI)
inline constexpr int IDC_LBL_TITLE        = 2105;
inline constexpr int IDC_LBL_SUB          = 2106;
inline constexpr int IDC_DL_LABEL         = 2107;
inline constexpr int IDC_PROGRESS         = 2108;

// Async messages
inline constexpr unsigned WM_APP_ASYNC_OK   = 0x8000 + 201; // WM_APP + 201
inline constexpr unsigned WM_APP_ASYNC_FAIL = 0x8000 + 202; // WM_APP + 202
inline constexpr unsigned WM_APP_BEGIN_DOWNLOAD = 0x8000 + 203; // WM_APP + 203
inline constexpr unsigned WM_APP_PROGRESS   = 0x8000 + 220; // WM_APP + 220

// Timer
inline constexpr unsigned IDT_INDET_PROGRESS = 0xBEEF;

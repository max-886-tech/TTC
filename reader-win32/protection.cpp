#ifndef NOMINMAX
#define NOMINMAX
#endif
#include "protection.h"

#ifndef WDA_EXCLUDEFROMCAPTURE
#define WDA_EXCLUDEFROMCAPTURE 0x00000011
#endif

void ApplyCaptureProtection(HWND hwnd, bool on) {
    if (!hwnd) return;
    if (!on) {
        SetWindowDisplayAffinity(hwnd, 0);
        return;
    }
    if (!SetWindowDisplayAffinity(hwnd, WDA_EXCLUDEFROMCAPTURE)) {
        SetWindowDisplayAffinity(hwnd, WDA_MONITOR);
    }
}

bool IsRemoteSession() {
    // SM_REMOTESESSION is reliable for RDP sessions.
    return GetSystemMetrics(SM_REMOTESESSION) != 0;
}

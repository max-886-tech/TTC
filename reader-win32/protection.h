#pragma once
#include <windows.h>

// Apply/clear capture protection for viewer window.
// Uses WDA_EXCLUDEFROMCAPTURE when available, falls back to WDA_MONITOR.
void ApplyCaptureProtection(HWND hwnd, bool on);

// True if app is running under a Remote Desktop / remote session.
bool IsRemoteSession();

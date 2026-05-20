#ifndef NOMINMAX
#define NOMINMAX
#endif

#include <windows.h>
#include "app_controller.h"

// Entry + routing only.
int WINAPI wWinMain(HINSTANCE hInst, HINSTANCE hPrev, PWSTR cmdLine, int cmdShow) {
    return AppRun(hInst, hPrev, cmdLine, cmdShow);
}

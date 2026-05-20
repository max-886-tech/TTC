#pragma once
#ifndef NOMINMAX
#define NOMINMAX
#endif
#include <windows.h>

// Entry point for the Win32 reader application.
// Keeps main.cpp minimal (entry + routing only).
int AppRun(HINSTANCE hInst, HINSTANCE hPrev, LPWSTR cmdLine, int nCmdShow);

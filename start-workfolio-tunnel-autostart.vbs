Set ws = CreateObject("Wscript.Shell")
ws.Run "powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""E:\Workfolio\WorkFolio\start-workfolio-tunnel.ps1"" -NoBrowser", 0, False
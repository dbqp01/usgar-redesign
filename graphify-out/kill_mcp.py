import psutil
import os
import signal

pid = os.getpid()
for proc in psutil.process_iter(['pid', 'name', 'cmdline']):
    try:
        cmd = proc.info.get('cmdline') or []
        if any('graphify.serve' in arg for arg in cmd) and proc.info['pid'] != pid:
            print(f"Killing process {proc.info['pid']}: {' '.join(cmd)}")
            os.kill(proc.info['pid'], signal.SIGTERM)
    except Exception as e:
        pass
print("Done checking processes")

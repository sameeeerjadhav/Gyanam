@echo off
cd /d E:\Projects\Gyanam
set "PATH=C:\Program Files\Git\cmd;%PATH%"
set GIT_AUTHOR_NAME=Sameer Jadhav
set GIT_AUTHOR_EMAIL=sameerpjadhav12@gmail.com
set GIT_COMMITTER_NAME=Sameer Jadhav
set GIT_COMMITTER_EMAIL=sameerpjadhav12@gmail.com

git checkout --orphan repush-temp
if errorlevel 1 exit /b 1
git add -A

(
echo Full codebase sync - Gyanam India portal and Exam portal.
echo.
echo Complete re-push of all project files.
) > "%TEMP%\gy_msg.txt"

for /f "delims=" %%i in ('git write-tree') do set TREE=%%i
echo TREE=%TREE%
for /f "delims=" %%i in ('git commit-tree %TREE% -F "%TEMP%\gy_msg.txt"') do set NEW=%%i
echo NEW=%NEW%
if "%NEW%"=="" (
  echo FAILED commit-tree
  exit /b 1
)

git branch -f main %NEW%
git checkout -f main
git branch -D repush-temp 2>nul

echo ===== VERIFY =====
git log -1 --format=fuller
git log -1 --format=%%B > "%TEMP%\gy_verify.txt"
type "%TEMP%\gy_verify.txt"
findstr /i /c:"cursor" /c:"Co-authored" "%TEMP%\gy_verify.txt" >nul
if not errorlevel 1 (
  echo ERROR: cursor trailer still present
  exit /b 1
)

echo CLEAN - pushing
git push --force origin main
del /q "%TEMP%\gy_msg.txt" "%TEMP%\gy_verify.txt" 2>nul
del /q "E:\Projects\Gyanam\_repush.bat" 2>nul
echo DONE

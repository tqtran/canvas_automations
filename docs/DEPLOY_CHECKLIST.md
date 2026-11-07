# Deploy to Canvas: server checklist

For a Windows server running IIS. Replace `C:\sites\canvas_automations` with the app folder
and `<pool>` with the site's IIS application pool name. Run the PowerShell lines in an
**elevated** PowerShell (Run as administrator).

## 1. Before you start

- [ ] Nobody is in the middle of a deployment. A run started on the old version does not
      carry over; it can simply be started again (courses already done are left alone).
- [ ] Back up the current app folder, including `config.local.php`:
  ```powershell
  Copy-Item C:\sites\canvas_automations "C:\backup\canvas_automations-$(Get-Date -f yyyyMMdd-HHmm)" -Recurse
  ```
- [ ] Find the php.exe IIS uses: IIS Manager > server node > **FastCGI Settings** > Full Path.
      Use this same php.exe for everything below.
- [ ] PHP is 8.2 or newer, with curl (or openssl) and mbstring:
  ```powershell
  & 'C:\PHP\php.exe' -v
  & 'C:\PHP\php.exe' -m | Select-String -Pattern '^(curl|openssl|mbstring)$'
  ```
- [ ] Find the application pool name: IIS Manager > Sites > the site > **Basic Settings** >
      Application pool.

## 2. Copy the new version

- [ ] Copy these from `main` into the app folder, replacing the old ones:
      `public\`, `src\`, `bin\`, `docs\`, `README.md`, `config.example.php`.
- [ ] Do **not** replace `config.local.php` or `var\`.
- [ ] The site's physical path still points at `public\`, and `public\web.config` is there.
- [ ] Unblock the PowerShell scripts (Windows marks downloaded files):
  ```powershell
  Unblock-File C:\sites\canvas_automations\bin\*.ps1
  ```

## 3. Folder permissions

- [ ] Give the app pool Modify on `var\`, for new and existing files:
  ```powershell
  New-Item -ItemType Directory C:\sites\canvas_automations\var -Force | Out-Null
  icacls C:\sites\canvas_automations\var /grant "IIS AppPool\<pool>:(OI)(CI)M" /T
  ```
- [ ] If the scheduled task will run as an account other than SYSTEM, grant that account the
      same (`/grant "DOMAIN\account:(OI)(CI)M" /T`). SYSTEM has access already.

## 4. Settings (optional)

All optional; `config.local.php` needs no changes to work.

- [ ] **Queue key** (recommended). Otherwise one is generated into `var\queue.key`. Generate one:
  ```powershell
  & 'C:\PHP\php.exe' -r "echo bin2hex(random_bytes(32));"
  ```
  and add it to `config.local.php`: `'queue_key' => '<the 64 characters>',`
  Set it before the first run; changing it later stops runs that are in progress.
- [ ] **Worker key**, only if the scheduled task will call the web address instead of php.exe:
      `'worker_key' => '<any random text, 16+ characters>',`

## 5. Check the page

- [ ] Open the site in a browser. Under **Server checks**:
  - [ ] Outbound HTTPS: **Ready**
  - [ ] Data folder: **Writable** (if not, redo step 3)
  - [ ] Queue worker: **Not running** (expected until step 6)

## 6. Scheduled task (recommended, not required)

Without it, runs still work: any open copy of the page keeps the queue moving, whoever
started the runs. With it, runs also carry on when every page is closed.

- [ ] Create it (it runs the worker once first to check):
  ```powershell
  cd C:\sites\canvas_automations
  .\bin\install-task.ps1 -PhpPath 'C:\PHP\php.exe'
  ```
  Expect a line with `"worker":"ran"`, then `Scheduled task 'Deploy to Canvas queue worker' created`.
  If PowerShell refuses to run the script:
  ```powershell
  powershell -ExecutionPolicy Bypass -File .\bin\install-task.ps1 -PhpPath 'C:\PHP\php.exe'
  ```
- [ ] After two minutes, the task's last result is 0:
  ```powershell
  Get-ScheduledTask -TaskName 'Deploy to Canvas queue worker' | Get-ScheduledTaskInfo | Select-Object LastRunTime, LastTaskResult
  ```
- [ ] The worker log has a line per minute and no `FAILED`:
  ```powershell
  Get-Content C:\sites\canvas_automations\var\worker.log -Tail 5
  ```
- [ ] Reload the page: Queue worker says **Running**.

## 7. Test runs

- [ ] Connect with your Canvas token. Choose **one or two courses** and click **Dry run**.
  - [ ] The timers move (Elapsed, Processed, Per course).
  - [ ] The status ends with "You can close this page" (or, without the task, "Keep this
        page open").
  - [ ] It ends with "Dry run complete", one result row per course.
- [ ] Click **View this run's log**. Expect, in order: `canvas_deploy_start`, `run_queued`,
      one or more `run_step` (`"by":"worker"` with the task, `"by":"page"` without),
      `course_result` per course, `run_ended` with `"status":"done"`.
- [ ] With the task running: start a dry run on several courses, **close the tab**, wait a
      minute, open the page again and connect. **Your runs** shows it; **View** shows its results.
- [ ] Cancel works: start a dry run on several courses, start a second one from another
      browser (it shows "Queued behind 1 run ... It starts automatically unless you cancel it"),
      click **Cancel this queued run** on the second, then **Cancel** twice under Your runs on
      the first. Both end as cancelled, and each run's log has `run_cancel_requested`.
- [ ] Real run on **one course**. In Canvas, check that course: the tool's tab in course
      navigation and its module, each seen by whoever was chosen for the run.
- [ ] No repeated "Lost contact with the server" messages. One now and then is fine; the run
      is on the server and the page keeps asking.

## Rollback

- [ ] Remove the task, then restore the backup from step 1:
  ```powershell
  .\bin\install-task.ps1 -Uninstall
  ```

## If something is wrong

| What you see | What to do |
|---|---|
| Page shows a 500 error | Check the PHP error log; check `var\` permissions (step 3). |
| Data folder: **Not writable** | Redo step 3 for the app pool shown in IIS. |
| Queue worker: **Not running** with the task created | `Get-Content var\worker.log -Tail 20`; Task Scheduler > the task > History. |
| `worker.log`: `php.exe not found` | Run `install-task.ps1` again with the right `-PhpPath`. |
| `worker.log`: `FAILED` with a permission error | The task's account needs Modify on `var\` (step 3). |
| Runs stay at "Waiting for its first turn" | Is the worker running? Look for `run_step_failed` in the run's log. |
| A course shows "Timed out" | Canvas was very slow for that course three times. Run it again later. |
| "Lost contact with the server" keeps repeating | Network or gateway trouble between you and the server; the run keeps going there. |
| Run stopped: "Canvas refused the token" | The token expired or was deleted. Make a new one and start again. |

Logs, all in `var\`: `audit.log` (everything each run did and why), `worker.log` (one line
per worker run), and the page's **View this run's log** for a single run.

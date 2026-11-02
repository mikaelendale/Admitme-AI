# AdmitMe browser extension (local build)

A Manifest V3 extension with a side panel. It lets the student's AdmitMe mentor work in **one tab** of their own Chrome — open a college's application portal, read it, fill it in and move through its steps — while the student watches and stays in charge. It also keeps the older, step-by-step way to fill one form with values the student approves.

What it never does, whoever asks: read or type passwords (the student logs in), set file inputs (the student chooses files), or solve CAPTCHAs. A click on anything final — submit, pay, sign, certify, send, delete… — happens only after the student presses **Allow** in the panel.

## Build and load

```bash
npm run build:extension      # type-checks, then builds into browser-extension/dist
```

Then open `chrome://extensions`, turn on **Developer mode**, choose **Load unpacked** and pick `browser-extension/dist`. The extension is not published to the Chrome Web Store. After reloading the extension, tabs that were already open get the content script again on first use.

## Use

1. In AdmitMe: **Settings → Browser extension → Create a link code**.
2. Click the extension's toolbar icon to open the side panel. Enter the AdmitMe address and the code.

### Let the mentor use this browser

3. Press **Let the mentor use this browser**. The mentor gets the current tab (or a new tab beside it, if the current tab is AdmitMe itself). Control lasts while the panel is open; reopening the panel resumes it.
4. Ask the mentor in AdmitMe to fill your application. The panel shows each action as it happens ("Opened lakeside.edu/apply", "Filled 6 fields", "Clicked “Save and continue”"…).
5. When a page needs a login or a CAPTCHA, the panel says so: do it yourself, then tell the mentor.
6. When the mentor wants to click something final, the panel shows **Allow / Deny**. No answer within 3 minutes counts as Deny.
7. **Stop** ends control at once (so does stopping it in AdmitMe or revoking the browser).

Rules the content script enforces on every page: password fields are listed without their value and cannot be filled; file inputs are never set; inputs that look like CAPTCHAs or typed signatures are left to the student; a consequential checkbox ("I certify…") cannot be ticked by a fill, only by a click the student allows; Enter is never used to submit a form whose button is consequential.

### Fill one form with approved values

3. Open the application form, choose the application in the panel, then press **Use this tab** → **Observe this page** → **Prepare fill**.
4. Approve the fields (in the panel, the application workspace, Approvals or chat). With the form tab in front, the panel types each value and reads it back. This flow never clicks or submits.

## How it is built

| File                        | Role                                                                                                                                                                                                                                                                                                |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `manifest.json`             | Permissions: `storage`, `sidePanel`, `tabs`, `scripting` (re-injects the content script into tabs opened before the extension was installed or reloaded); host access to `https://*/*` and local `http://127.0.0.1` / `localhost`                                                                   |
| `src/sidepanel/main.tsx`    | React side panel: linking, the start view, and the approved-fill sessions                                                                                                                                                                                                                           |
| `src/sidepanel/control.tsx` | Mentor control: polls `GET /api/browser/agent-sessions/{id}/next` (also the heartbeat), runs one command at a time in the mentor's tab, reports `POST /api/browser/agent-commands/{id}/result`, shows the activity list, the Allow card and Stop                                                    |
| `src/sidepanel/api.ts`      | The AdmitMe API client (bearer token from linking) and the panel's tab                                                                                                                                                                                                                              |
| `src/content.ts`            | Reads the page into a `PageModel` (temporary `data-admitme-id` ids, labels, values, alerts, login/CAPTCHA flags; open shadow roots and same-origin frames included) and fills, clicks and presses keys on those elements; also observes/performs the approved-fill steps. Built as a classic script |
| `src/background.ts`         | Opens the panel from the toolbar icon. Nothing depends on it staying alive                                                                                                                                                                                                                          |
| `src/shared/agent.ts`       | Mentor-control contract: HTTP API, commands, `PageModel`, results, panel ↔ content-script messages                                                                                                                                                                                                  |
| `src/shared/commands.ts`    | Approved-fill contract shared by the panel and the content script                                                                                                                                                                                                                                   |

For tests, the panel can be opened as a normal page bound to one tab: `chrome-extension://<id>/sidepanel/index.html?tab=<tabId>`.

The server side is `routes/browser.php`, `App\Domain\Browser\*` and decisions D-027–D-030 in `docs/admitme/decisions.md`.

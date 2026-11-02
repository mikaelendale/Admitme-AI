/**
 * Background service worker. Deliberately minimal: the side panel does the work while it is open, so
 * nothing depends on a continuously alive worker (Manifest V3 may stop it at any time).
 */
chrome.sidePanel
    .setPanelBehavior({ openPanelOnActionClick: true })
    .catch(() => undefined);

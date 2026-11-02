/* Minimal typings for the Chrome extension APIs this extension uses (avoids adding a dependency). */

declare namespace chrome {
    namespace runtime {
        const onMessage: {
            addListener(
                cb: (
                    message: any,
                    sender: any,
                    sendResponse: (response?: any) => void,
                ) => boolean | void,
            ): void;
        };
        const id: string;
    }
    namespace tabs {
        type Tab = {
            id?: number;
            index?: number;
            windowId?: number;
            url?: string;
            pendingUrl?: string;
            title?: string;
            /** 'loading' | 'complete' (and 'unloaded' for discarded tabs). */
            status?: string;
            active?: boolean;
        };
        type TabChangeInfo = { status?: string; url?: string; title?: string };
        type TabUpdatedListener = (
            tabId: number,
            changeInfo: TabChangeInfo,
            tab: Tab,
        ) => void;
        function query(q: {
            active?: boolean;
            currentWindow?: boolean;
        }): Promise<Tab[]>;
        function get(tabId: number): Promise<Tab>;
        function create(properties: {
            url?: string;
            active?: boolean;
            index?: number;
            windowId?: number;
        }): Promise<Tab>;
        function update(
            tabId: number,
            properties: { url?: string; active?: boolean },
        ): Promise<Tab | undefined>;
        function sendMessage(tabId: number, message: unknown): Promise<any>;
        const onUpdated: {
            addListener(cb: TabUpdatedListener): void;
            removeListener(cb: TabUpdatedListener): void;
        };
    }
    namespace windows {
        function update(
            windowId: number,
            info: { focused?: boolean },
        ): Promise<unknown>;
    }
    namespace scripting {
        function executeScript(injection: {
            target: { tabId: number; allFrames?: boolean };
            files: string[];
        }): Promise<{ frameId: number; result?: unknown }[]>;
    }
    namespace storage {
        const local: {
            get(keys: string | string[] | null): Promise<Record<string, any>>;
            set(items: Record<string, any>): Promise<void>;
            remove(keys: string | string[]): Promise<void>;
        };
    }
    namespace sidePanel {
        function setPanelBehavior(behavior: {
            openPanelOnActionClick: boolean;
        }): Promise<void>;
    }
}

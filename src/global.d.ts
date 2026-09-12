export {};

declare global {
  interface Window {
    __preloaderDone?: boolean;
    __preloaderFallbackBound?: boolean;
    __jsClassBound?: boolean;
    __myBookingsInitialized?: boolean;
    __profilePageLoadBound?: boolean;
    __loginPageLoadBound?: boolean;
    __usgarFormatPrice?: (amount: unknown, currency?: string, locale?: string) => string;
    __usgarGetUser?: (forceRefresh?: boolean) => Promise<any>;
  }
}

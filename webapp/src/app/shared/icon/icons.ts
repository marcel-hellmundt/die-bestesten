export const ICONS: Record<string, string> = {
  upload: `<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M10 13V7M7 10l3-3 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M6 15h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
  </svg>`,

  'upload-error': `<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M10 6v5M10 14h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
    <circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.4"/>
  </svg>`,

  pencil: `<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M8.5 1.5l2 2-6 6-2.5.5.5-2.5 6-6z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
  </svg>`,

  plus: `<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
  </svg>`,

  x: `<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
  </svg>`,

  stadium: `<svg width="13" height="13" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M2 13V6l6-4 6 4v7" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
    <rect x="5" y="9" width="2.5" height="4" rx="0.5" fill="currentColor" opacity="0.5"/>
    <rect x="8.5" y="9" width="2.5" height="4" rx="0.5" fill="currentColor" opacity="0.5"/>
    <path d="M2 13h12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
  </svg>`,

  pin: `<svg width="13" height="13" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M8 1.5C5.515 1.5 3.5 3.515 3.5 6c0 3.5 4.5 8.5 4.5 8.5S12.5 9.5 12.5 6c0-2.485-2.015-4.5-4.5-4.5z" stroke="currentColor" stroke-width="1.4"/>
    <circle cx="8" cy="6" r="1.5" fill="currentColor"/>
  </svg>`,

  trash: `<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M2 3h8M4.5 3V2a1 1 0 011-1h1a1 1 0 011 1v1M3 3l.5 7a1 1 0 001 1h3a1 1 0 001-1L9 3" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>`,

  filter: `<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M2 4h12M4.5 8h7M7 12h2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
  </svg>`,

  'external-link': `<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M6.5 3H3a1 1 0 00-1 1v9a1 1 0 001 1h9a1 1 0 001-1V10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M9 2h5v5M13.5 2.5L7 9" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>`,

  eye: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/>
  </svg>`,

  gavel: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="m14.5 12.5-8 8a2.119 2.119 0 0 1-3-3l8-8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="m16 16 6-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="m8 8 6-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="m9 7 8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>`,

  swap: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M8 3 4 7l4 4M4 7h16M16 21l4-4-4-4M20 17H4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>`,

  // Gleiche Zeichnung wie 'settings' im linken Menü (app/core/icon/icon.component.ts) — dort
  // separates Icon-Set fürs Nav; hier dupliziert, damit dieses Icon-Set (app/shared/icon) optisch
  // dazu passt, ohne die beiden Icon-Komponenten zusammenzulegen.
  settings: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
    <circle cx="12" cy="12" r="3"/>
  </svg>`,

  // Einstellungen → Benachrichtigungen (gleiche Zeichnungen wie spieltag/award/sticker im Nav-Icon-Set)
  spieltag: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
  </svg>`,

  award: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>
  </svg>`,

  sticker: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M7.93 10.9C8.29 9.86 5.18 8.45 2.7 7.36A2.2 2.2 0 0 1 5.52 3.99C7.02 6.26 9.22 7.88 10.26 7.51C11.29 7.13 11.97 7.62 11.97 4.98A1.3 1.3 0 0 1 14.53 5.44C13.63 7.92 13.55 8.04 14.46 8.65C15.37 9.27 17.22 7.81 19.12 5.96A1.6 1.6 0 0 1 20.82 8.68C18.32 9.58 17.18 10.74 17.28 11.84C17.38 12.93 15.89 12.82 18.5 13.31A1.5 1.5 0 0 1 17.37 16.09C15.16 14.63 15.61 14.35 14.83 15.13C14.05 15.91 15.54 17.77 17.13 19.93A1.9 1.9 0 0 1 13.61 21.36C13.25 18.7 12.49 17.3 11.4 17.16C10.31 17.03 9.67 17.16 8.57 19.57A1.5 1.5 0 0 1 6.14 17.81C8.1 16.01 8.45 15.43 7.94 14.46C7.42 13.49 5.64 13.63 3.06 14.31A1.7 1.7 0 0 1 2.95 10.92C5.57 11.42 7.58 11.94 7.93 10.9Z"/>
  </svg>`,

  // Auslosung (H2H)
  shuffle: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="m18 14 4 4-4 4"/><path d="m18 2 4 4-4 4"/><path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.1-8.6c.7-1.1 2-1.7 3.3-1.7H22"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/><path d="M22 18h-5.9c-1.3 0-2.6-.7-3.3-1.8l-.5-.8"/>
  </svg>`,

  // Direktangebote ("Hinterzimmerdeals")
  handshake: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>
  </svg>`,
};

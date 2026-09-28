import { createContext, useContext, useEffect, useState } from 'react';

const ThemeCtx = createContext({
  dark: false, toggle: () => {},
  chatBg: '', bgMode: 'fit', saveBg: () => {}, clearBg: () => {},
});
export const useTheme = () => useContext(ThemeCtx);

/** Downscale an image file to a JPEG data URL (fits localStorage limits). */
export function fileToBgDataUrl(file, maxDim = 1600) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      try {
        const scale = Math.min(1, maxDim / Math.max(img.width, img.height));
        const w = Math.max(1, Math.round(img.width * scale));
        const h = Math.max(1, Math.round(img.height * scale));
        const cv = document.createElement('canvas');
        cv.width = w; cv.height = h;
        cv.getContext('2d').drawImage(img, 0, 0, w, h);
        URL.revokeObjectURL(url);
        resolve(cv.toDataURL('image/jpeg', 0.85));
      } catch (e) { reject(e); }
    };
    img.onerror = reject;
    img.src = url;
  });
}

export function ThemeProvider({ children }) {
  const [dark, setDark] = useState(() => {
    try { return localStorage.getItem('sms-theme') === 'dark'; }
    catch { return false; }
  });
  const [chatBg, setChatBg] = useState(() => {
    try { return JSON.parse(localStorage.getItem('sms-bg') || '{}').dataUrl || ''; }
    catch { return ''; }
  });
  const [bgMode, setBgMode] = useState(() => {
    try { return JSON.parse(localStorage.getItem('sms-bg') || '{}').mode || 'fit'; }
    catch { return 'fit'; }
  });

  useEffect(() => {
    document.documentElement.classList.toggle('dark', dark);
    try { localStorage.setItem('sms-theme', dark ? 'dark' : 'light'); } catch {}
  }, [dark]);

  useEffect(() => {
    const root = document.documentElement;
    if (chatBg) {
      root.style.setProperty('--chat-bg', `url("${chatBg}")`);
      root.style.setProperty('--chat-bg-repeat', bgMode === 'repeat' ? 'repeat' : 'no-repeat');
      root.style.setProperty('--chat-bg-size', bgMode === 'repeat' ? 'auto' : 'cover');
    } else {
      root.style.removeProperty('--chat-bg');
    }
    try { localStorage.setItem('sms-bg', JSON.stringify({ dataUrl: chatBg, mode: bgMode })); } catch {}
  }, [chatBg, bgMode]);

  const saveBg = (dataUrl, mode) => {
    if (dataUrl !== undefined) setChatBg(dataUrl);
    if (mode) setBgMode(mode);
  };
  const clearBg = () => setChatBg('');

  return (
    <ThemeCtx.Provider value={{ dark, toggle: () => setDark((d) => !d), chatBg, bgMode, saveBg, clearBg }}>
      {children}
    </ThemeCtx.Provider>
  );
}

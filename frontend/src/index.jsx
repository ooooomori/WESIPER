import React, { StrictMode } from 'react';
import ReactDOM from 'react-dom/client';
import './index.css';

import App from './App';
import reportWebVitals from './reportWebVitals';

import { BrowserRouter } from 'react-router-dom';

const enableFreshBuildCheck = () => {
    if (import.meta.env.DEV) return;

    const loadedBundle = document.querySelector('script[type="module"][src]')?.getAttribute('src');
    let checking = false;

    const refreshIfUpdated = async () => {
        if (checking) return;
        checking = true;

        try {
            const checkUrl = new URL(window.location.href);
            checkUrl.searchParams.set('__build_check', Date.now().toString());
            const response = await fetch(checkUrl.toString(), {
                cache: 'no-store',
                headers: { 'Cache-Control': 'no-cache' },
            });
            if (!response.ok) return;

            const html = await response.text();
            const latestDocument = new DOMParser().parseFromString(html, 'text/html');
            const latestBundle = latestDocument.querySelector('script[type="module"][src]')?.getAttribute('src');

            if (loadedBundle && latestBundle && loadedBundle !== latestBundle) {
                window.location.reload();
            }
        } catch {
            // 오프라인이거나 일시적인 통신 오류라면 현재 화면을 그대로 유지한다.
        } finally {
            checking = false;
        }
    };

    window.addEventListener('pageshow', refreshIfUpdated);
    window.addEventListener('focus', refreshIfUpdated);
    document.addEventListener('visibilitychange', refreshIfUpdated);
    window.setTimeout(refreshIfUpdated, 0);
    window.setInterval(refreshIfUpdated, 60_000);
};

enableFreshBuildCheck();

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(
    <>
        <BrowserRouter>
            <StrictMode>
                <App />
            </StrictMode>
            
        </BrowserRouter>
        
    </>
    
);

// If you want to start measuring performance in your app, pass a function
// to log results (for example: reportWebVitals(console.log))
// or send to an analytics endpoint. Learn more: https://bit.ly/CRA-vitals
reportWebVitals();

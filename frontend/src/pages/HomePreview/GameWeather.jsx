export default function GameWeather({ weather }) {
    if (!weather) return null;
    const pty = Number(weather.precipitationType);
    const sunny = pty === 0 && Number(weather.sky) === 1;
    const label = `기상청 · 경기 시작 시각 예보 ${weather.forecastTime.slice(-4, -2)}시: ${weather.condition}, ${weather.temperature}°C, 강수확률 ${weather.precipitationProbability}%, 강수량 ${weather.precipitation}, 풍속 ${weather.windSpeed}m/s, 습도 ${weather.humidity}%`;
    return <svg className="home-preview-weather" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" role="img" aria-label={label}>
        <title>{label}</title>
        {sunny ? <><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></> : <>
            {pty === 0 && Number(weather.sky) === 3 && <><circle cx="7" cy="7" r="3"/><path d="M7 1v1M1 7h1m1-4 1 1"/></>}
            <path d="M6 16a4 4 0 0 1-1-7.9 6 6 0 0 1 11.5-.6A4.3 4.3 0 1 1 18 16Z"/>
            {(pty === 1 || pty === 2) && <path d="m8 19-1 3m6-3-1 3"/>}
            {(pty === 2 || pty === 3) && <path d="M18 19v4m-2-2h4"/>}
            {pty === 3 && <path d="M8 19v4m-2-2h4"/>}
            {pty === 4 && <path d="m13 17-3 4h4l-2 3"/>}
        </>}
    </svg>;
}

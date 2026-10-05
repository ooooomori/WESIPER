import "./App.css";
import { Route, Routes, Navigate, useLocation } from "react-router-dom";
import PlayerProfile from './pages/PlayerProfile';
import Nav from "./components/Nav.jsx";
import Main from "./pages/Main";
import Kbodle from "./pages/Kbodle";
import Kbobingo from "./pages/Kbobingo";
import Lineup from "./pages/Lineup";
function App() {
    const location = useLocation();
    const pid = new URLSearchParams(location.search).get('pid');
    return (
        <div className={location.pathname === '/' && pid !== null ? 'player-profile-shell' : undefined}>
            <Nav />
            <Routes>
                <Route path="/" element={pid !== null ? <PlayerProfile key={pid} pid={pid} /> : <Main />} />
                <Route path="/kbodle" element={<Kbodle />} />
                <Route path="/bingo" element={<Kbobingo />} />
                <Route path="/lineup" element={<Lineup />} />
                <Route path="/gameday" element={<Navigate to="/" replace />} />
                <Route path="/kbocandle" element={<Navigate to="/" replace />} />
                <Route path="/grid" element={<Navigate to="/bingo" />} />
            </Routes>
        </div>
    );
}

export default App;

import "./App.css";
import { Route, Routes, Navigate } from "react-router-dom";
import Nav from "./components/Nav.jsx";
import CandlePromotion from "./components/CandlePromotion.jsx";
import Main from "./pages/Main";
import HomePreview from "./pages/HomePreview";
import Kbodle from "./pages/Kbodle";
import Kbobingo from "./pages/Kbobingo";
import Gameday from "./pages/Gameday";
import Kbocandle from "./pages/Kbocandle";
function App() {
    return (
        <div>
            <Nav />
            <CandlePromotion />
            <Routes>
                <Route path="/" element={<Main />} />
                <Route path="/home-preview" element={<HomePreview />} />
                <Route path="/kbodle" element={<Kbodle />} />
                <Route path="/bingo" element={<Kbobingo />} />
                <Route path="/gameday" element={<Gameday />} />
                <Route path="/kbocandle" element={<Kbocandle />} />
                <Route path="/grid" element={<Navigate to="/bingo" />} />
            </Routes>
        </div>
    );
}

export default App;

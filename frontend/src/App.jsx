import "./App.css";
import { Route, Routes, Navigate } from "react-router-dom";
import Nav from "./components/Nav.jsx";
import Main from "./pages/Main";
import Kbodle from "./pages/Kbodle";
import Kbobingo from "./pages/Kbobingo";
import Gameday from "./pages/Gameday";
import Kbocandle from "./pages/Kbocandle";
function App() {
    return (
        <div>
            <Nav />
            <Routes>
                <Route path="/" element={<Main />} />
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

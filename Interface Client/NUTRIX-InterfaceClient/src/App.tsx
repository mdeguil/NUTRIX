import { Routes, Route } from 'react-router-dom';

// Layout partagé (Sidebar + TopBar)
import AppLayout from './components/layout/AppLayout';

// Pages publiques — sans sidebar
import Login from './pages/Login/Login';
import Register from './pages/Register/Register';
import NotFound from './pages/NotFound/NotFound';

// Pages applicatives — avec sidebar
import Dashboard from './pages/Dashboard/Dashboard';
import OccupantsList from './pages/Occupants/OccupantsList';
import OccupantDetail from './pages/Occupants/OccupantDetail';
import Journal from './pages/Journal/Journal';
import StockList from './pages/Stock/StockList';
import StockDetail from './pages/Stock/StockDetail';
import Previsionnel from './pages/Previsionnel/Previsionnel';
import Planificateur from './pages/Planificateur/Planificateur';
import Agriculture from './pages/Agriculture/Agriculture';

function App() {
  return (
    <Routes>
      <Route path="/" element={<Login />} />
      <Route path="/register" element={<Register />} />

      <Route element={<AppLayout />}>
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/occupants" element={<OccupantsList />} />
        <Route path="/occupants/:id" element={<OccupantDetail />} />
        <Route path="/journal" element={<Journal />} />
        <Route path="/stock" element={<StockList />} />
        <Route path="/stock/:id" element={<StockDetail />} />
        <Route path="/previsionnel" element={<Previsionnel />} />
        <Route path="/planificateur" element={<Planificateur />} />
        <Route path="/agriculture" element={<Agriculture />} />
      </Route>

      <Route path="*" element={<NotFound />} />
    </Routes>
  );
}

export default App;

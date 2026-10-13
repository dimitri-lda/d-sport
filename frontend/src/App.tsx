import { Link, Outlet, Route, Routes } from 'react-router';
import { BackendStatus } from './components/BackendStatus';

function Layout() {
  return (
    <div className="mx-auto max-w-3xl px-6 py-10">
      <header className="mb-10 border-b border-slate-200 pb-5">
        <Link to="/" className="text-lg font-semibold">
          Sport E
        </Link>
        <p className="mt-1 text-sm text-slate-500">
          Технический каркас проекта
        </p>
      </header>
      <main>
        <Outlet />
      </main>
    </div>
  );
}

function HomePage() {
  return (
    <>
      <h1 className="text-3xl font-semibold tracking-tight">
        Основа приложения
      </h1>
      <p className="mt-4 mb-8 leading-relaxed text-slate-600">
        Первый этап: инфраструктура Symfony API и React SPA. Бизнес-логика и
        перенос legacy будут рассмотрены на следующем этапе.
      </p>
      <BackendStatus />
    </>
  );
}

function NotFoundPage() {
  return (
    <>
      <h1 className="text-3xl font-semibold">404 — страница не найдена</h1>
      <p className="mt-4 text-slate-600">Указанный адрес не существует.</p>
      <Link to="/" className="mt-6 inline-block underline underline-offset-4">
        На главную
      </Link>
    </>
  );
}

export function App() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<HomePage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  );
}

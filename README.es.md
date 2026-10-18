# 🌙 NightGuardian

*Monitoreo de errores y salud de tus aplicaciones, self-hosted. Se queda despierto para que vos no tengas que hacerlo.*

[English](README.md) · [Arquitectura](docs/architecture.md) · [Protocolo](docs/protocol/v1.md) · [Roadmap](ROADMAP.md)

## ¿Por qué?

Las apps se rompen a las 3 de la mañana y te enterás a las 9, por un cliente. Las herramientas
alojadas cobran por usuario, por evento y con una tarjeta que nunca terminás de dar de baja.
NightGuardian es la alternativa:

- **Self-hosted, gratis, MIT.** Tu server, tu base, tus datos.
- **Todo por API.** El colector habla un [protocolo HTTP](docs/protocol/v1.md) chico y documentado.
  Laravel es el primer SDK; pueden venir PHP, Node, Python o Delphi.
- **Nunca rompe tu app.** El SDK envía *después* de responder, con timeouts cortos, corte automático
  y cola en disco. Si el colector se cae, la app ni se entera y no se pierde nada.
- **Silencioso.** Agrupa errores y avisa solo cuando algo es **nuevo**, **reaparece** o **se dispara**.
- **Amable con el usuario final.** Página de error con código de referencia y un campo para
  contar qué estaba haciendo.
- **Laravel + Filament.** Si sabés Laravel, podés colaborar hoy.

## El panel

| Dashboard | Triage |
|---|---|
| ![Dashboard con una tarjeta por proyecto](docs/img/panel-dashboard.png) | ![Lista de triage con filtros y acciones masivas](docs/img/panel-triage.png) |

| Detalle de un issue | Comentarios de usuarios |
|---|---|
| ![Detalle con stack trace y contexto de código](docs/img/panel-issue.png) | ![Pestaña de comentarios con lo que hacía el usuario](docs/img/panel-issue-feedback.png) |

| Tokens (se muestran una vez, con el bloque de instalación) | Modo oscuro |
|---|---|
| ![Token nuevo con el bloque .env y el comando composer](docs/img/panel-tokens.png) | ![Detalle en modo oscuro](docs/img/panel-issue-dark.png) |

![Búsqueda global por código de referencia](docs/img/panel-search.png)

- **Dashboard:** una tarjeta por proyecto (issues abiertos, nuevos hoy, eventos en 24 h, gráfico de 7 días).
- **Triage:** filtros por proyecto, estado, entorno, tipo, nivel y release; orden por último visto o eventos; resolver o ignorar en masa.
- **Detalle del issue:** último evento con el stack trace (frames de la app resaltados, contexto de código), petición, usuario, etiquetas y contexto; pestañas de eventos, **comentarios** y actividad; acciones para resolver, resolver en un release, ignorar (1 h / 24 h / siempre), reabrir y fusionar.
- **Búsqueda:** el buscador encuentra un evento por el código de referencia que te da un usuario (`NG-7F3K-92QD`, en mayúsculas o minúsculas, con o sin guiones) y los issues por título.
- **Tokens:** al crear un token se muestra **una sola vez**, con las dos líneas para el `.env` de la app y `composer require nightguardian/laravel`; solo se guarda su hash.
- **Acceso:** enlaces de invitación (firmados, 7 días, un solo uso) para proyectos y para la instancia; los super admins gestionan usuarios.

## Cola y scheduler

Los avisos, los resúmenes y la retención necesitan un worker de cola y el scheduler. En producción: un worker con
systemd/Supervisor y una línea de cron (`* * * * * php artisan schedule:run`). Para probar en local, en dos consolas:

```powershell
php artisan queue:work --tries=3
php artisan schedule:work
```

Los tokens de bots de Telegram se cargan en el panel (proyecto → *Canales de aviso*), se guardan cifrados y nunca van a archivos.

## Inicio rápido

```bash
# Colector (PHP 8.3+, MySQL 8 / MariaDB 10.6+)
git clone https://github.com/pablozagni/nightguardian.git
cd nightguardian
composer install
cp .env.example .env            # y completá DB_DATABASE, DB_USERNAME y DB_PASSWORD
php artisan key:generate
php artisan nightguardian:install
```

`nightguardian:install` revisa los requisitos, crea las tablas y tu primer super admin. Los avisos, los resúmenes
y la retención necesitan un worker de cola y el scheduler: [docs/deploy.md](docs/deploy.md) es una guía para copiar y
pegar por SSH en **Ubuntu 22.04 + Apache + PHP-FPM + MySQL** (HTTPS, worker, cron, actualizaciones y backups).
Para probarlo antes, con **Docker**:

```bash
docker compose up -d --build
docker compose exec app php artisan nightguardian:install --skip-migrate     # y abrí http://localhost:8080
```

En local, sin worker ni cron: `php artisan queue:work` y `php artisan schedule:work` en dos consolas.

```bash
# En tu app Laravel (5.8 a 13), con el token que muestra el panel una sola vez
composer require nightguardian/laravel
# .env: NIGHTGUARDIAN_URL y NIGHTGUARDIAN_TOKEN
php artisan nightguardian:test
```

## Colaborar

Mirá los issues `good first issue`, sumá canales de aviso (Slack, Discord, webhooks), traducciones
o un SDK nuevo. Leé [CONTRIBUTING.md](CONTRIBUTING.md).

Licencia MIT © Pablo Zagni y colaboradores. Nacido en [z.system](https://www.z-system.com.ar), Argentina.

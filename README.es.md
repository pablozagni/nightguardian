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

## Inicio rápido

```bash
# Colector (PHP 8.3+, MySQL 8 / MariaDB 10.6+)
git clone https://github.com/pablozagni/nightguardian.git
cd nightguardian && composer install && cp .env.example .env
php artisan nightguardian:install

# En tu app Laravel (5.8 a 13)
composer require nightguardian/laravel
# .env: NIGHTGUARDIAN_URL y NIGHTGUARDIAN_TOKEN (el token se crea en el panel)
php artisan nightguardian:test
```

## Colaborar

Mirá los issues `good first issue`, sumá canales de aviso (Slack, Discord, webhooks), traducciones
o un SDK nuevo. Leé [CONTRIBUTING.md](CONTRIBUTING.md).

Licencia MIT © Pablo Zagni y colaboradores. Nacido en [z.system](https://www.z-system.com.ar), Argentina.

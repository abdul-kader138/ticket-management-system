# Ticket Management System

A Laravel application (PHP 8.3, [Filament](https://filamentphp.com) admin panel, React + Vite + Tailwind
frontend) for managing support/service tickets.

## Tech Stack

- **Backend:** Laravel 8.3+, Filament (admin panel)
- **Frontend:** React 19, Vite, Tailwind CSS
- **Database:** MySQL

## Getting Started

### Prerequisites

- PHP 8.3+
- Composer
- Node.js + npm
- MySQL

### Setup

```bash
# Install dependencies
composer install
npm install

# Configure environment
cp .env.example .env
php artisan key:generate

# Set DB_* values in .env, then create the database, then run migrations
php artisan migrate

# Build frontend assets
npm run dev    # for local development
# or
npm run build  # for production
```

### Running the app

```bash
php artisan serve
```

Visit `http://localhost:8000`.

## Publishing this project to GitHub

This project is a fresh Git repository. Before publishing it, check that no
passwords, API keys, or other confidential data are included. The `.env` file
is ignored and must not be committed.

1. Sign in to [GitHub](https://github.com) and select **New repository**.
2. Enter a repository name, such as `ticket-management-system`, and choose
   **Private** or **Public**.
3. Leave **Add a README**, **Add .gitignore**, and **Choose a license** disabled,
   because this local project already contains Git files.
4. Create the repository, then copy its HTTPS URL.
5. From this project's directory, run the following commands, replacing the URL
   with the one GitHub provides:

```bash
git status
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin https://github.com/YOUR-USERNAME/ticket-management-system.git
git push -u origin main
```

If Git reports that `origin` already exists, update it instead:

```bash
git remote set-url origin https://github.com/YOUR-USERNAME/ticket-management-system.git
git push -u origin main
```

GitHub may ask you to authenticate in a browser or use a personal access token;
your normal GitHub password cannot be used as an HTTPS Git password.

For later updates, use:

```bash
git add .
git commit -m "Describe the changes"
git push
```

Do not commit `.env`. Other developers should create it locally from
`.env.example`, then install and configure the application in their own
environment.

## License

This project is proprietary. All rights reserved.

# Calculatrice Pro (PHP natif)

Application web de calculatrice moderne, responsive et sans framework (PHP natif + HTML/CSS/JS), restructurée avec autoload Composer, tests et séparation front.

## Fonctionnalités
- **Calculs**: `+ - * / ^`, parenthèses, décimaux
- **Fonctions**: `sin`, `cos`, `tan`, `sqrt`, `log` (base10), `ln`, `pow(a,b)`
- **Historique en session** avec annotations et réutilisation rapide
- **Lien de partage** d'un calcul via `?q=...`
- **Conversions**: longueurs (km/m/cm/mm), devises (EUR/USD/GBP, taux fixes démo)
- **Graphique**: tracer `f(x)` côté client (Chart.js)
- **Thèmes** clair/sombre, **reconnaissance vocale** (si supportée par le navigateur)

## Installation
1. PHP 8+ requis.
2. Installer les dépendances Composer:
```bash
composer install
```

### Démarrage (serveur PHP intégré)
```bash
php -S localhost:8000 -t public
```
Puis ouvrez `http://localhost:8000/`.

### Démarrage avec Docker
```bash
docker compose up --build
```
Ouvrez `http://localhost:8080/`.

## Utilisation
- Entrez une expression (ex: `2*sin(3.14159/2)+sqrt(9)-log(100)`) puis « Calculer ».
- Ajoutez une note facultative; le calcul est enregistré dans l'historique.
- Cliquez un élément de l'historique pour réutiliser l'expression.
- Le lien de partage se met à jour automatiquement.
- Onglet Conversion: choisissez la catégorie, la valeur et les unités.
- Onglet Graphique: entrez `f(x)` puis « Tracer ».

## Astuces
- Trigonométrie en radians.
- `log(x)` = log base 10; `ln(x)` = log naturel.
- Bouton 🎤 pour dicter l'expression (navigateurs compatibles).

## Limites & Sécurité
- L'évaluateur parse l'expression (shunting-yard), pas d'`eval()` PHP.
- CSRF activé pour les POST; en-têtes de sécurité + CSP de base.
- Taux de change statiques (démo). Pour la production, branchez une API FX.

## Tests
```bash
composer test
```

## Licence
MIT

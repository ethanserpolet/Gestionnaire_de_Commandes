# Commandes St Marc

Application web de **demandes d’achat** du Lycée St Marc. Elle remplace le formulaire Excel
« Demande d’achat préalable à une commande » : création des demandes, circuit de validation,
passage des commandes auprès des fournisseurs, notifications par e-mail et bon PDF.

- **URL** : https://commandes.st-marc.eu
- **Connexion** : comptes Microsoft 365 `@st-marc.eu` (Entra ID, OAuth2)
- **Pile** : PHP 8.1+ natif (aucune dépendance Composer), MariaDB, Microsoft Graph
- **Guide utilisateur** : [`docs/Guide-Commandes-St-Marc.pdf`](docs/Guide-Commandes-St-Marc.pdf)

---

## Sommaire

1. [Fonctionnement](#fonctionnement)
2. [Rôles](#rôles)
3. [Installation](#installation)
4. [Configuration Entra ID](#configuration-entra-id)
5. [Variables d’environnement](#variables-denvironnement)
6. [Mise à jour](#mise-à-jour)
7. [Administration](#administration)
8. [Structure du code](#structure-du-code)
9. [Sécurité](#sécurité)
10. [Dépannage](#dépannage)

---

## Fonctionnement

```
Demandeur ──► Responsable de service ──► Comptabilité + Chef d’établissement ──► Exécution par fournisseur ──► Finalisée
  (envoi,        (étape 1, choisi          (étape 2, les deux                      (exécuteur attitré du
   signature)     parmi ceux du service)     obligatoires s’ils existent)            fournisseur, sinon comptabilité)
```

| Statut | Signification |
|---|---|
| Brouillon | En préparation, visible par son seul auteur, modifiable |
| En attente | Envoyée : numéro attribué, première validation attendue |
| En validation | Au moins une validation obtenue |
| Validé | Toutes les validations obtenues, à passer auprès des fournisseurs |
| Finalisé | Toutes les parts (une par fournisseur) ont été passées |
| Refusé | Refus d’un validateur, avec motif — clôt la commande |

**Circuit de validation** (figé au moment de l’envoi) :

1. **Étape 1 — responsable de service** : l’unique responsable du service du demandeur, ou celui
   que le demandeur choisit s’il y en a plusieurs. Étape sautée si le service n’a pas de
   responsable ou si le demandeur est lui-même responsable du service.
2. **Étape 2 — comptabilité et chef d’établissement** : une validation par rôle, par n’importe
   quel membre du rôle (étape sautée pour un rôle sans titulaire), plus chaque utilisateur ayant
   le rôle générique « Validateur ». Cette étape ne s’ouvre qu’après l’étape 1.

**Exécution** : à la validation, la commande est découpée **en une part par fournisseur**.
Chaque part est confiée à l’**exécuteur attitré** du fournisseur (Administration › Fournisseurs).
Fournisseur absent de la liste ou sans exécuteur actif : la part revient à la **comptabilité**
(à défaut, aux utilisateurs ayant le rôle Exécuteur). La commande passe « Finalisée » quand
toutes ses parts sont marquées comme passées.

**E-mails** : envoyés via Microsoft Graph **au nom de la personne qui agit** (son jeton de
session), jamais à elle-même. Envoi, étape validée, rappel, commande validée (aux exécuteurs
concernés et au demandeur), refus, commande passée.

**Pièces jointes** : PDF et images (15 Mo max), par glisser-déposer, parcourir ou collage
d’une capture d’écran (Win + Maj + S puis Ctrl + V). Stockées hors de la racine web.

**PDF** : bon de demande d’achat imprimable (`/commandes/{id}/pdf`) avec signatures
horodatées et images en annexe, généré par le navigateur.

## Rôles

| Rôle | Droits |
|---|---|
| Demandeur | Créer et envoyer des demandes, suivre les siennes. **Attribué automatiquement** à tout nouvel inscrit |
| Responsable de service | Valider en étape 1 les demandes de son service |
| Comptabilité | Valider en étape 2 ; passer les commandes des fournisseurs sans exécuteur attitré |
| Chef d’établissement | Valider en étape 2 |
| Validateur | Validation supplémentaire nominative en étape 2 (facultatif) |
| Exécuteur | Passer les commandes des fournisseurs qui lui sont attribués |
| Lecteur | Consulter les commandes validées et finalisées |
| Administrateur | Utilisateurs, services, destinations, fournisseurs, simulation |

Un utilisateur peut cumuler plusieurs rôles ; le menu s’adapte.

## Installation

### Prérequis

- PHP **8.1+** avec `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `session`
- MariaDB 10.5+ (ou MySQL 8)
- HTTPS obligatoire (l’URI de redirection Entra doit être en `https://`)

### Étapes

1. Copier les fichiers sur le serveur. **La racine web doit pointer sur `public/`**
   (Plesk : *Paramètres d’hébergement › Racine du document* = `commandes.st-marc.eu/public`).
   `src/`, `views/`, `database/`, `storage/` et `.env` ne doivent jamais être servis.
2. Créer la base MariaDB et son utilisateur (Plesk › Bases de données).
3. Copier `.env.example` en `.env` et le remplir (voir ci-dessous).
4. Ouvrir `https://commandes.st-marc.eu/_setup.php?key=<APP_SETUP_KEY>` (clé définie dans `.env`) : crée les tables,
   les rôles et les destinations par défaut. **Supprimer ensuite `public/_setup.php` du serveur.**
5. Vérifier que PHP peut écrire dans `storage/uploads/` (créé automatiquement).
6. Se connecter avec un compte listé dans `APP_ADMIN_EMAILS`, puis configurer l’application
   (voir [Administration](#administration)).

Serveur Apache : les `.htaccess` fournis gèrent la réécriture d’URL. Serveur IIS :
`public/web.config` (module URL Rewrite requis).

## Configuration Entra ID

Dans **Entra ID › Inscriptions d’applications › CommandePanel** :

- **Authentification** : plateforme *Web*, URI de redirection
  `https://commandes.st-marc.eu/auth/callback` (identique à `ENTRA_REDIRECT_URI`).
- **Certificats et secrets** : un secret client → `ENTRA_APP_SECRET_VALUE` (la *valeur*, pas l’ID).
  Noter sa date d’expiration : le renouveler avant, sinon plus personne ne peut se connecter.
- **Autorisations API** (Microsoft Graph, *déléguées*), avec **consentement administrateur accordé** :

| Permission | Usage |
|---|---|
| `openid`, `profile`, `email`, `offline_access` | Connexion et maintien de session |
| `User.Read` | Lecture du profil (nom, e-mail) |
| `Mail.Send` | Envoi des notifications au nom de l’utilisateur |
| `GroupMember.Read.All` | Refus des élèves (`APP_BLOCKED_GROUPS`) — requis dès que la variable est renseignée |

### Refuser l’accès aux élèves

Deux protections complémentaires :

1. **Dans l’application** : `APP_BLOCKED_GROUPS=GP_eleves`. À chaque connexion, les groupes de
   l’utilisateur (y compris imbriqués, nom Entra ou nom AD `onPremisesSamAccountName`) sont
   lus via Graph ; un membre d’un groupe listé est refusé **avant** toute création de compte.
   Si Graph refuse la lecture (permission manquante), la connexion est refusée à tous sauf aux
   comptes de `APP_ADMIN_EMAILS`, qui voient l’erreur pour pouvoir corriger.
2. **Dans Entra (recommandé en plus)** : *Applications d’entreprise › CommandePanel ›
   Propriétés › Affectation requise = Oui*, puis n’affecter que les groupes du personnel
   (l’affectation de groupes nécessite Entra ID P1, inclus dans les licences A3/A5).

## Variables d’environnement

Fichier `.env` à la racine du projet (modèle : `.env.example`). **Ne jamais le versionner.**

| Variable | Obligatoire | Description |
|---|---|---|
| `DATABASE_HOST` | oui | `hôte:port`, ex. `localhost:3306` |
| `DATABASE_NAME` | oui | Nom de la base |
| `DATABASE_USER` / `DATABASE_PASSWORD` | oui | Identifiants MariaDB |
| `ENTRA_APP_ID_CLIENT` | oui | ID d’application (client) |
| `ENTRA_APP_ID_LOCATAIRE` | oui | ID de l’annuaire (locataire) |
| `ENTRA_APP_SECRET_VALUE` | oui | Valeur du secret client |
| `ENTRA_REDIRECT_URI` | oui | `https://commandes.st-marc.eu/auth/callback` |
| `APP_ADMIN_EMAILS` | oui | Administrateurs, séparés par des virgules (toujours admins, même après retrait manuel) |
| `APP_BLOCKED_GROUPS` | non | Groupes interdits de connexion, ex. `GP_eleves` |
| `APP_SETUP_KEY` | oui | Clé exigée par `_setup.php` (aléatoire, 12 caractères minimum) |
| `APP_DEBUG` | non | `1` affiche le détail des erreurs 500 — **`0` en production** |
| `ENTRA_APP_ID_OBJECT`, `ENTRA_APP_SECRET_ID` | non | Pour mémoire, non utilisés |

## Mise à jour

1. Transférer les fichiers modifiés (FTP / gestionnaire de fichiers Plesk).
2. Si `database/schema.sql` ou `src/Support/Migrations.php` ont changé : remettre
   `public/_setup.php`, l’ouvrir avec `?key=<APP_SETUP_KEY>`, puis le supprimer. Il est idempotent : il ne crée
   que ce qui manque et ne touche pas aux données.
3. Les fichiers CSS/JS sont versionnés dans `views/layout.php` (`?v=…`) : incrémenter pour
   forcer le rechargement des navigateurs.

## Administration

Menu **Administration** (comptes admin uniquement) :

- **Utilisateurs** : recherche, filtres (rôle, service, statut), tri, pagination par 50,
  compteurs rapides (sans rôle, sans service, désactivés…), modification d’un compte dans une
  fenêtre et **actions groupées** (ajouter/retirer un rôle, changer de service, activer,
  désactiver). Un administrateur ne peut ni se désactiver ni se retirer ses droits.
- **Services** : services et leurs responsables (plusieurs possibles). Chaque utilisateur
  choisit son service à la connexion tant qu’il n’en a pas.
- **Destinations** : arborescence libre pôles › lieux › sous-lieux. Les lignes de commande
  gardent le chemin complet en texte.
- **Fournisseurs** : liste proposée à la saisie et **exécuteur attitré** de chacun.
- **Simulation** : contrôle de la configuration et rejeu complet d’une commande avec les vraies
  personnes configurées, dans une transaction annulée, e-mails interceptés. Bouton d’envoi
  d’un vrai e-mail de test à soi-même pour vérifier `Mail.Send`.

Ordre conseillé à la mise en service : rôles (Comptabilité, Chef d’établissement, Responsables,
Exécuteurs) › Services › Destinations › Fournisseurs › Simulation.

## Structure du code

```
public/                 Racine web : index.php (routes), assets/, .htaccess, web.config, _setup.php
src/
  Auth/                 EntraAuth (OAuth2, Graph, groupes), Session
  Http/                 Router, Guards (accès, choix du service), Controllers/
  Repositories/         Accès MariaDB (PDO, requêtes préparées)
  Services/             CommandeService (circuit), GraphMailer, MailTemplate, UploadService, SimulationService
  Support/              Config (.env), View (rendu, icônes, dates), Roles, Csrf, Url, Migrations
views/                  Gabarits PHP (layout, pages, partials)
database/               schema.sql, seed.sql
storage/uploads/        Pièces jointes (hors web, non versionné)
docs/                   Guide utilisateur PDF et sa source
```

## Sécurité

- Authentification déléguée à Microsoft ; aucun mot de passe stocké. Comptes `@st-marc.eu` seulement.
- Jetons d’accès gardés en session uniquement (jamais en base), rafraîchis automatiquement.
- Requêtes SQL préparées ; échappement systématique en sortie ; jeton CSRF sur tous les formulaires.
- Pièces jointes : type vérifié par le contenu (pas l’extension), nom aléatoire, stockage hors
  web, téléchargement après contrôle d’accès.
- Cookies de session `Secure`, `HttpOnly`, `SameSite=Lax`.
- `.env` exclu du dépôt (`.gitignore`) et bloqué par `.htaccess`.

## Dépannage

| Symptôme | Piste |
|---|---|
| Page 500 sur tout le site | Base pas à jour : relancer `_setup.php`. Sinon `APP_DEBUG=1` pour voir l’erreur |
| `AADSTS50011` (redirect URI) | `ENTRA_REDIRECT_URI` différente de l’URI déclarée dans Entra (au caractère près) |
| `AADSTS65001` (consentement) | Une permission demandée n’a pas le consentement admin (souvent `GroupMember.Read.All`) |
| « Impossible de vérifier votre accès » | Lecture des groupes refusée : ajouter et consentir `GroupMember.Read.All` |
| E-mails non reçus | Administration › Simulation › « M’envoyer un e-mail de test » ; vérifier `Mail.Send` |
| Commande bloquée « En attente » | Responsable ou validateur désactivé : Simulation › contrôle de la configuration |
| Commande validée jamais passée | Fournisseur sans exécuteur actif et aucune comptabilité : voir Fournisseurs |

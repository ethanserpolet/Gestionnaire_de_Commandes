-- Schema for Site_Validation_Commandes (MariaDB)
-- Existing databases are upgraded by src/Support/Migrations.php (run through public/_setup.php).

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) UNIQUE NOT NULL,
    label VARCHAR(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO roles (code, label) VALUES
    ('demandeur', 'Demandeur'),
    ('validateur', 'Validateur'),
    ('executeur', 'Exécuteur'),
    ('lecteur', 'Lecteur'),
    ('responsable_service', 'Responsable de service'),
    ('comptabilite', 'Comptabilité'),
    ('chef_etablissement', 'Chef d’établissement');

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entra_object_id VARCHAR(64) UNIQUE NOT NULL,
    email VARCHAR(190) UNIQUE NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    service_id INT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) UNIQUE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_responsables (
    service_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (service_id, user_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Arborescence libre : pôles (parent_id NULL) > lieux > sous-lieux…
CREATE TABLE IF NOT EXISTS destinations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES destinations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) UNIQUE NOT NULL,
    executeur_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commandes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_commande VARCHAR(30) UNIQUE NULL,
    demandeur_id INT NOT NULL,
    service_id INT NULL,
    statut ENUM('brouillon','en_attente','en_validation','valide','finalise','refuse') NOT NULL DEFAULT 'brouillon',
    date_demande DATE NOT NULL,
    motif_refus TEXT NULL,
    submitted_at DATETIME NULL,
    finalized_at DATETIME NULL,
    finalized_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (demandeur_id) REFERENCES users(id),
    FOREIGN KEY (finalized_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commande_lignes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commande_id INT NOT NULL,
    fournisseur VARCHAR(150) NOT NULL,
    description TEXT NULL,
    destination VARCHAR(255) NOT NULL,
    motif VARCHAR(190) NULL,
    quantite INT NOT NULL DEFAULT 1,
    prix_unitaire DECIMAL(10,2) NOT NULL DEFAULT 0,
    prix_ttc DECIMAL(10,2) NOT NULL DEFAULT 0,
    ordre INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commande_ligne_fichiers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commande_ligne_id INT NOT NULL,
    nom_original VARCHAR(255) NOT NULL,
    nom_stocke VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NULL,
    taille_octets INT NULL,
    uploaded_by INT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (commande_ligne_id) REFERENCES commande_lignes(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per validation "slot". etape 1 = responsable de service, etape 2 = comptabilité /
-- chef d'établissement / validateurs. A slot is either assigned to one user (assigned_to) or open
-- to any member of its role (assigned_to NULL). validateur_id = who actually decided.
CREATE TABLE IF NOT EXISTS commande_validations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commande_id INT NOT NULL,
    etape TINYINT NOT NULL DEFAULT 2,
    role VARCHAR(30) NOT NULL DEFAULT 'validateur',
    assigned_to INT NULL,
    validateur_id INT NULL,
    decision ENUM('en_attente','valide','refuse') NOT NULL DEFAULT 'en_attente',
    commentaire TEXT NULL,
    decided_at DATETIME NULL,
    FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE CASCADE,
    FOREIGN KEY (validateur_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exécution d'une commande validée, une ligne par fournisseur : confiée à l'exécuteur attitré du
-- fournisseur (assigned_to), sinon ouverte à tous les membres d'un rôle (comptabilité par défaut).
CREATE TABLE IF NOT EXISTS commande_executions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commande_id INT NOT NULL,
    fournisseur VARCHAR(150) NOT NULL,
    assigned_to INT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'comptabilite',
    done_by INT NULL,
    done_at DATETIME NULL,
    FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE CASCADE,
    FOREIGN KEY (done_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commande_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commande_id INT NOT NULL,
    user_id INT NULL,
    type VARCHAR(30) NOT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_commandes_statut ON commandes(statut);
CREATE INDEX IF NOT EXISTS idx_commandes_demandeur ON commandes(demandeur_id);
CREATE INDEX IF NOT EXISTS idx_lignes_commande ON commande_lignes(commande_id);
CREATE INDEX IF NOT EXISTS idx_fichiers_ligne ON commande_ligne_fichiers(commande_ligne_id);
CREATE INDEX IF NOT EXISTS idx_validations_commande ON commande_validations(commande_id);
CREATE INDEX IF NOT EXISTS idx_events_commande ON commande_events(commande_id);
CREATE INDEX IF NOT EXISTS idx_executions_commande ON commande_executions(commande_id);

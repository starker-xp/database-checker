# database-checker

> **Archive** — Outil PHP de comparaison et synchronisation de schémas MySQL. Compare la structure d'une base de données (ou un export JSON) avec une structure de référence et génère les instructions SQL (`ALTER`, `CREATE`, `DROP`) nécessaires pour les synchroniser. **Utilisé en production** dans le cadre d'une migration d'un projet legacy PHP 4.x vers PHP 5.X.

[![Coverage Status](https://coveralls.io/repos/github/starker-xp/database-checker/badge.svg?branch=master)](https://coveralls.io/github/starker-xp/database-checker?branch=master) [![Build Status](https://travis-ci.org/starker-xp/database-checker.svg?branch=master)](https://travis-ci.org/starker-xp/database-checker) [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/starker-xp/database-checker/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/starker-xp/database-checker/?branch=master)

## Contexte

Sur un projet de migration d'une application legacy PHP 4.x vers PHP 5.X, je me suis retrouvé confronté à un problème de synchronisation de **plus de 200 bases de données clients**. Les migrations de schéma étaient jusqu'alors jouées manuellement, ce qui entraînait régulièrement des erreurs : colonnes manquantes, index absents, collations incohérentes d'une instance à l'autre. Il n'existait aucun outil pour vérifier automatiquement si une instance client était conforme au schéma de référence. J'ai développé cet outil pour fiabiliser ce processus dans un délai contraint d'un mois.

> *Analyse rétrospective réalisée en 2026 dans le cadre d'un nettoyage et d'une mise en archive de mes dépôts GitHub/GitLab.*

## Fonctionnalités

```
┌─────────────────────────────────────────────────────────────────┐
│                      database-checker                           │
├─────────────────────────────────────────────────────────────────┤
│  Source de données    Base MySQL live ou fichier JSON            │
│  Diff de schéma       Tables, colonnes, index, clés primaires   │
│  Génération SQL       CREATE TABLE, ALTER TABLE, DROP COLUMN    │
│  Collations           Vérification DB / table / colonne         │
│  Moteurs              Vérification InnoDB, MyISAM, MEMORY...    │
│  Optimisation         ENUM('0','1') → TINYINT(1)               │
│  Sécurité             DROP désactivé par défaut                 │
│  Export               Structure → JSON versionnable             │
└─────────────────────────────────────────────────────────────────┘
```

### Workflow type

```
  Base MySQL client          Schéma de référence (JSON)
        │                              │
        ▼                              ▼
  MysqlDatabaseFactory          JsonDatabaseFactory
        │                              │
        ▼                              ▼
   MysqlDatabase ◄──── diff ────► MysqlDatabase
                         │
                         ▼
              Statements SQL (ALTER, CREATE, DROP)
```

## Architecture

```
src/
├── Checker/
│   └── MysqlDatabaseCheckerService.php   # Moteur de diff entre deux MysqlDatabase
├── Exception/                             # 10 exceptions métier spécifiques
├── Factory/
│   ├── JsonDatabaseFactory.php            # Construit MysqlDatabase depuis un JSON
│   └── MysqlDatabaseFactory.php           # Construit MysqlDatabase depuis MySQL live
├── Repository/
│   ├── MysqlRepository.php                # Requêtes INFORMATION_SCHEMA
│   └── StructureInterface.php             # Abstraction pour le mock en tests
├── Structure/
│   ├── DatabaseInterface.php              # Contrat commun (create/alter/delete)
│   ├── MysqlDatabase.php                  # Modèle : base de données
│   ├── MysqlDatabaseTable.php             # Modèle : table (colonnes + index)
│   ├── MysqlDatabaseColumn.php            # Modèle : colonne (type, nullable, default...)
│   └── MysqlDatabaseIndex.php             # Modèle : index (unique, primary, standard)
└── LoggerTrait.php                        # PSR-3 logger intégré

tests/
├── Checker/MysqlDatabaseCheckerServiceTest.php
├── Factory/JsonDatabaseFactoryTest.php
├── Factory/MysqlDatabaseFactoryTest.php
├── Structure/MysqlDatabaseColumnTest.php
├── Structure/MysqlDatabaseIndexTest.php
├── Structure/MysqlDatabaseTableTest.php
├── Structure/MysqlDatabaseTest.php
└── LoggetTraitTest.php
```

## Points techniques notables

- **Modèle objet complet** : `MysqlDatabase` → `MysqlDatabaseTable` → `MysqlDatabaseColumn` / `MysqlDatabaseIndex` — chaque niveau sait générer ses propres statements SQL (`createStatement`, `alterStatement`, `deleteStatement`)
- **Double source** : la structure peut être construite depuis une base MySQL live (`INFORMATION_SCHEMA`) ou depuis un fichier JSON versionné en Git
- **Validation JSON** : utilisation de `Symfony\Component\OptionsResolver` pour valider la structure du JSON d'entrée avec des valeurs par défaut
- **Gestion des index lors d'un ALTER** : les index sont supprimés avant la modification d'une colonne puis recréés — c'est une contrainte MySQL souvent oubliée
- **DROP sécurisé** : les instructions `DROP COLUMN` ne sont générées que si `enableDropStatement()` est explicitement appelé — sécurité par défaut
- **Comparaison case-insensitive** : les noms de tables, colonnes et index sont comparés en minuscules pour gérer les incohérences de casse
- **PSR-3 Logger** : toutes les classes utilisent un `LoggerTrait` compatible PSR-3, permettant d'injecter n'importe quel logger (Monolog, etc.)
- **Export JSON** : `MysqlDatabaseFactory::exportStructure()` permet d'exporter le schéma actuel en JSON pour le versionner

## Compétences démontrées

- **Résolution de problème concret** : outil développé pour un besoin réel de synchronisation multi-instances en production
- **Architecture objet** : modèle riche avec interfaces, exceptions métier, factories, repository pattern
- **Tests unitaires** : 8 fichiers de tests vérifiant les statements SQL générés (tests de comportement)
- **CI/CD** : Travis CI + Coveralls (couverture) + Scrutinizer (qualité)
- **Interopérabilité** : PSR-3 (logger), PSR-4 (autoload), Symfony OptionsResolver
- **Connaissance MySQL** : INFORMATION_SCHEMA, collations, moteurs de stockage, gestion des index

## Limitations connues

Ce projet étant un outil développé dans un délai contraint (1 mois), certaines limitations ont été identifiées avec le recul :

| Limitation | Détail |
|---|---|
| **Pas de FOREIGN KEY** | Les clés étrangères ne sont pas gérées dans le diff |
| **FULLTEXT partiel** | Les index FULLTEXT sont détectés mais pas typés correctement dans le modèle |
| **Pas de RENAME COLUMN** | Une colonne renommée est vue comme un DROP + CREATE |
| **Pas de filtrage** | Impossible d'ignorer certaines tables, colonnes ou index |

## Alternatives modernes

Ce projet a été développé à une époque où les outils disponibles ne couvraient pas ce besoin spécifique (diff multi-instances sans Doctrine). Aujourd'hui, plusieurs alternatives existent :

- **[Doctrine Migrations](https://www.doctrine-project.org/projects/migrations.html)** — gestion de migrations versionnées (nécessite Doctrine ORM)
- **[Phinx](https://phinx.org/)** — migrations de base de données indépendantes du framework
- **[Laravel Migrations](https://laravel.com/docs/migrations)** — intégré à Laravel
- **[Liquibase](https://www.liquibase.org/)** / **[Flyway](https://flywaydb.org/)** — outils multi-langages de gestion de schéma
- **[mysqldbcompare](https://dev.mysql.com/doc/mysql-utilities/1.5/en/mysqldbcompare.html)** — utilitaire MySQL natif de comparaison de schémas

L'intérêt de database-checker restait sa capacité à comparer un schéma de référence (JSON) avec 200+ instances live sans dépendance ORM.

## Installation

```bash
composer require starker-xp/database-checker
```

## Utilisation

```php
// Depuis une base MySQL live
$pdo = new PDO('mysql:host=localhost', 'user', 'pass');
$repository = new MysqlRepository($pdo);
$factory = new MysqlDatabaseFactory($repository, 'ma_base');
$currentDatabase = $factory->generate();

// Depuis un fichier JSON de référence
$json = file_get_contents('schema-reference.json');
$jsonFactory = new JsonDatabaseFactory($json);
$referenceDatabase = $jsonFactory->generate('ma_base');

// Générer le diff
$checker = new MysqlDatabaseCheckerService();
$checker->enableCheckCollate();   // optionnel
$checker->enableCheckEngine();    // optionnel
$checker->enableDropStatement();  // optionnel — désactivé par défaut
$statements = $checker->diff($currentDatabase, $referenceDatabase);

// $statements contient les ALTER/CREATE/DROP SQL à exécuter
```

## Prérequis

- PHP >= 7.1.3
- Symfony OptionsResolver ^4.0
- PSR Log ^1.0
- Accès à `INFORMATION_SCHEMA` pour l'introspection MySQL live

## Licence

MIT

<?php

/*
 * ==========================================================
 * PULL v2 — voir Cloud/SYNC_V2.md
 * ==========================================================
 *
 * GET sync/v2/pull.php?depuis=<curseur>   (premier pull : depuis=-1)
 *
 * Renvoie uniquement les lignes modifiées depuis le curseur
 * (suppressions comprises), plus l'entreprise et la licence.
 */

// Compression gzip si le client l'accepte (Accept-Encoding).
ob_start("ob_gzhandler");

header("Content-Type: application/json; charset=utf-8");

require_once "../../config.php";
require_once "../../auth.php";
require_once "../../licence_commun.php";

try
{
    $contexte = cs_contexte_depuis_jeton($pdo);

    if ($contexte === null)
        cs_repondre_erreur("Authentification manquante.", "AUTH", 401);

    $entrepriseId = $contexte["EntrepriseId"];

    $depuis = isset($_GET["depuis"]) ? intval($_GET["depuis"]) : -1;

    /*
     * mdp=2 : l'appareil ne reçoit plus les hachages des mots de passe, seulement
     * une empreinte qui lui signale un changement (il garde son propre hachage,
     * créé à la connexion Cloud). Sans ce paramètre : ancien format, pour les
     * versions pas encore mises à jour.
     */
    $colonneMotDePasse = ($_GET["mdp"] ?? "") === "2"
        ? "LEFT(SHA2(Password, 256), 16) AS MotDePasseVersion"
        : "Password";

    /*
     * Le curseur est lu AVANT les données : une écriture validée pendant
     * ce pull aura une version > curseur et sera renvoyée au pull suivant
     * (au pire deux fois, jamais perdue).
     */
    $stmt = $pdo->prepare("
        SELECT
            EntrepriseId,
            NomEntreprise,
            TypeActivite,
            Adresse,
            Telephone,
            Email,
            Devise,
            TVA,
            Logo,
            DateCreation,
            UpdatedAt,
            IsDeleted,
            IFNULL(DerniereVersion, 0) AS DerniereVersion
        FROM entreprise_cloud
        WHERE EntrepriseId = ?
        LIMIT 1
    ");

    $stmt->execute([$entrepriseId]);

    $entreprise = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entreprise)
        cs_repondre_erreur("Entreprise introuvable.");

    $curseur = (int)$entreprise["DerniereVersion"];

    unset($entreprise["DerniereVersion"]);

    /*
     * Licence de référence (voir licence_commun.php). Expirée : seules
     * l'entreprise et la licence sont renvoyées, le curseur ne bouge pas ;
     * les données reprendront après l'activation d'une nouvelle clé.
     */
    $licence = cs_licence_entreprise($pdo, $entrepriseId);

    if (!cs_licence_est_valide($licence))
    {
        cs_activite_refus("LICENCE_EXPIREE", "Pull : licence expirée");

        echo json_encode([
            "success" => true,
            "licenceExpiree" => true,
            "curseur" => $depuis,
            "entreprise" => $entreprise,
            "licence" => $licence
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $tables = [
        "users" => [
            "user_cloud",
            "EntrepriseId, Username, $colonneMotDePasse, Name, LastName, Role,
             IFNULL(IsActive, 1) AS IsActive, SyncId, IsDeleted"
        ],
        "fournisseurs" => [
            "fournisseur_cloud",
            "EntrepriseId, NomFournisseur, Telephone, Email, Adresse,
             Contact, Notes, SyncId, IsDeleted"
        ],
        "clients" => [
            "client_cloud",
            "EntrepriseId, NomClient, Telephone, Email, Adresse,
             Solde, SyncId, IsDeleted"
        ],
        "produits" => [
            "produit_cloud",
            "EntrepriseId, CodeProduit, NomProduit, Categorie, SousCategorie,
             Marque, Fournisseur, Reference, Unite, Conditionnement,
             PrixAchat, PrixVente, QuantiteStock, StockMinimum,
             DateApprovisionnement, DateExpiration, Description,
             ImageProduit, Actif, SyncId, IsDeleted"
        ],
        "depenses" => [
            "depense_cloud",
            "EntrepriseId, DateDepense, Libelle, Montant, Categorie,
             Utilisateur, SyncId, IsDeleted, ApprovisionnementSyncId"
        ],
        "approvisionnements" => [
            "approvisionnement_cloud",
            "EntrepriseId, CodeProduit, NomProduit, Quantite, PrixAchat,
             TotalAchat, Fournisseur, DateAppro, Utilisateur, SyncId,
             EnregistreCommeDepense, IsDeleted"
        ],
        "ventes" => [
            "vente_cloud",
            "EntrepriseId, Client, DateVente, Montant, MontantPaye, Reste,
             Statut, Vendeur, CheminFacturePDF, ValideCaisse,
             DateValidationCaisse, SyncId, IsDeleted"
        ],
        "lignesVente" => [
            "ligne_vente_cloud",
            "EntrepriseId, VenteSyncId, CodeProduit, NomProduit, Categorie,
             Unite, Fournisseur, Reference, Quantite, PrixUnitaire,
             Montant, SyncId, IsDeleted"
        ],
        "paiementsVente" => [
            "paiement_vente_cloud",
            "EntrepriseId, VenteSyncId, DatePaiement, Montant, Vendeur,
             TypePaiement, ValideCaisse, DateValidationCaisse, SyncId, IsDeleted"
        ],
        "historiques" => [
            "historique_cloud",
            "EntrepriseId, DateAction, Utilisateur, Action, Details,
             SyncId, IsDeleted"
        ]
    ];

    $reponse = [
        "success" => true,
        "curseur" => $curseur,
        "entreprise" => $entreprise,
        "licence" => $licence ?: null
    ];

    foreach ($tables as $cle => $definition)
    {
        list($table, $colonnes) = $definition;

        $stmt = $pdo->prepare("
            SELECT $colonnes
            FROM `$table`
            WHERE EntrepriseId = ?
              AND Version > ?
              AND Version <= ?
            ORDER BY Version
        ");
        // Pas de "Id" dans le tri : vente_cloud a pour clé IdVente.

        $stmt->execute([$entrepriseId, $depuis, $curseur]);

        $reponse[$cle] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode($reponse, JSON_UNESCAPED_UNICODE);
}
catch (Exception $e)
{
    error_log("sync/v2/pull.php : " . $e->getMessage());
    cs_repondre_erreur("Erreur serveur pendant la récupération.");
}

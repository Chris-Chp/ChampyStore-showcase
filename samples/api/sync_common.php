<?php

/*
 * ==========================================================
 * FONCTIONS COMMUNES DE SYNCHRONISATION (v1 et v2)
 * ==========================================================
 *
 * Voir Cloud/SYNC_V2.md pour le protocole.
 */


/*
 * Réserve la prochaine version de l'entreprise.
 *
 * Doit être appelée DANS une transaction : la ligne entreprise reste
 * verrouillée jusqu'au commit, ce qui sérialise les écritures d'une même
 * entreprise. Un pull ne peut donc jamais voir la version N+1 validée
 * avant la version N.
 */
function cs_allouer_version($pdo, $entrepriseId)
{
    $stmt = $pdo->prepare("
        SELECT IFNULL(DerniereVersion, 0)
        FROM entreprise_cloud
        WHERE EntrepriseId = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([$entrepriseId]);

    $actuelle = $stmt->fetchColumn();

    if ($actuelle === false)
        throw new Exception("Entreprise introuvable dans le Cloud.");

    $version = (int)$actuelle + 1;

    $maj = $pdo->prepare("
        UPDATE entreprise_cloud
        SET DerniereVersion = ?
        WHERE EntrepriseId = ?
    ");

    $maj->execute([$version, $entrepriseId]);

    return $version;
}


/*
 * Enregistre un mouvement de stock.
 * Idempotent : un SyncId déjà connu n'est pas compté deux fois.
 * Retourne true si le mouvement est nouveau.
 */
function cs_ajouter_mouvement(
    $pdo,
    $entrepriseId,
    $syncId,
    $produitSyncId,
    $quantite,
    $type,
    $dateMouvement,
    $utilisateur,
    $version)
{
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO mouvement_stock_cloud
        (
            EntrepriseId,
            SyncId,
            ProduitSyncId,
            Quantite,
            Type,
            DateMouvement,
            Utilisateur,
            Version,
            UpdatedAt,
            IsDeleted
        )
        VALUES
        (
            :EntrepriseId,
            :SyncId,
            :ProduitSyncId,
            :Quantite,
            :Type,
            :DateMouvement,
            :Utilisateur,
            :Version,
            NOW(),
            0
        )
    ");

    $stmt->execute([
        ":EntrepriseId" => $entrepriseId,
        ":SyncId" => $syncId,
        ":ProduitSyncId" => $produitSyncId,
        ":Quantite" => (int)$quantite,
        ":Type" => mb_substr((string)$type, 0, 30),
        ":DateMouvement" => empty($dateMouvement) ? date("Y-m-d H:i:s") : $dateMouvement,
        ":Utilisateur" => (string)$utilisateur,
        ":Version" => $version
    ]);

    return $stmt->rowCount() === 1;
}


function cs_nouveau_sync_id()
{
    return bin2hex(random_bytes(16));
}


/*
 * Recalcule le stock des produits indiqués à partir de leurs mouvements.
 */
function cs_recalculer_stock($pdo, $entrepriseId, $produitSyncIds, $version)
{
    $produitSyncIds = array_values(array_unique(array_filter(
        array_map("strval", $produitSyncIds),
        function ($id) { return $id !== ""; }
    )));

    if (count($produitSyncIds) === 0)
        return;

    $stmt = $pdo->prepare("
        UPDATE produit_cloud p
        SET
            p.QuantiteStock =
            (
                SELECT IFNULL(SUM(m.Quantite), 0)
                FROM mouvement_stock_cloud m
                WHERE m.EntrepriseId = p.EntrepriseId
                  AND m.ProduitSyncId = p.SyncId
                  AND IFNULL(m.IsDeleted, 0) = 0
            ),
            p.Version = ?,
            p.UpdatedAt = NOW()
        WHERE p.EntrepriseId = ?
          AND p.SyncId = ?
    ");

    foreach ($produitSyncIds as $produitSyncId)
    {
        $stmt->execute([$version, $entrepriseId, $produitSyncId]);
    }
}


/*
 * Convertit un stock absolu (client v1) en mouvement d'ajustement,
 * puis recalcule le stock. Garde le comportement v1 pour ces postes.
 */
function cs_ajuster_stock_absolu(
    $pdo,
    $entrepriseId,
    $produitSyncId,
    $stockEnvoye,
    $utilisateur,
    $version)
{
    if ((string)$produitSyncId === "")
        return;

    $stmt = $pdo->prepare("
        SELECT IFNULL(SUM(Quantite), 0)
        FROM mouvement_stock_cloud
        WHERE EntrepriseId = ?
          AND ProduitSyncId = ?
          AND IFNULL(IsDeleted, 0) = 0
    ");

    $stmt->execute([$entrepriseId, $produitSyncId]);

    $ecart = (int)$stockEnvoye - (int)$stmt->fetchColumn();

    if ($ecart !== 0)
    {
        cs_ajouter_mouvement(
            $pdo,
            $entrepriseId,
            cs_nouveau_sync_id(),
            $produitSyncId,
            $ecart,
            "AJUSTEMENT",
            date("Y-m-d H:i:s"),
            $utilisateur,
            $version
        );
    }

    cs_recalculer_stock($pdo, $entrepriseId, [$produitSyncId], $version);
}


/*
 * Fait revenir des lignes au prochain pull (donnée refusée par les droits) :
 * la valeur serveur remplacera la modification locale.
 */
function cs_toucher_versions($pdo, $table, $entrepriseId, $syncIds, $version)
{
    $tablesAutorisees = [
        "user_cloud",
        "produit_cloud",
        "approvisionnement_cloud",
        "historique_cloud"
    ];

    if (!in_array($table, $tablesAutorisees, true))
        throw new Exception("Table non autorisée : " . $table);

    $stmt = $pdo->prepare("
        UPDATE `$table`
        SET Version = ?
        WHERE EntrepriseId = ?
          AND SyncId = ?
    ");

    foreach ($syncIds as $syncId)
    {
        if ((string)$syncId !== "")
            $stmt->execute([$version, $entrepriseId, (string)$syncId]);
    }
}

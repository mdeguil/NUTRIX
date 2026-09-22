<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Attention : kcal_100g / proteines_g_100g / glucides_g_100g / lipides_g_100g / fibres_g_100g
 * sont des colonnes VARCHAR(50) en base (pas DECIMAL) — voir MOTEUR_CALCUL.md §1 (ecart #8).
 * Mappees ici en string pour rester fidele au schema existant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'Recette')]
#[ApiResource]
class Recette
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'Id_Recette')]
    private ?int $id = null;

    #[ORM\Column(name: 'libelle', length: 50, unique: true)]
    #[Assert\NotBlank]
    private ?string $libelle = null;

    #[ORM\Column(name: 'proteines_g_100g', length: 50)]
    #[Assert\NotBlank]
    private ?string $proteinesG100g = null;

    #[ORM\Column(name: 'glucides_g_100g', length: 50)]
    #[Assert\NotBlank]
    private ?string $glucidesG100g = null;

    #[ORM\Column(name: 'lipides_g_100g', length: 50)]
    #[Assert\NotBlank]
    private ?string $lipidesG100g = null;

    #[ORM\Column(name: 'fibres_g_100g', length: 50)]
    #[Assert\NotBlank]
    private ?string $fibresG100g = null;

    #[ORM\Column(name: 'poids_total_g', type: 'float')]
    #[Assert\Positive]
    private ?float $poidsTotalG = null;

    #[ORM\Column(name: 'kcal_100g', length: 50)]
    #[Assert\NotBlank]
    private ?string $kcal100g = null;

    #[ORM\Column(name: 'temps_preparation', length: 50, nullable: true)]
    private ?string $tempsPreparation = null;

    #[ORM\Column(name: 'cycle_production', type: 'smallint', nullable: true)]
    private ?int $cycleProduction = null;

    #[ORM\ManyToOne(targetEntity: CategorieRecette::class)]
    #[ORM\JoinColumn(name: 'Id_categorie_recette', referencedColumnName: 'Id_categorie_recette', nullable: true)]
    private ?CategorieRecette $categorieRecette = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getProteinesG100g(): ?string
    {
        return $this->proteinesG100g;
    }

    public function setProteinesG100g(string $proteinesG100g): static
    {
        $this->proteinesG100g = $proteinesG100g;

        return $this;
    }

    public function getGlucidesG100g(): ?string
    {
        return $this->glucidesG100g;
    }

    public function setGlucidesG100g(string $glucidesG100g): static
    {
        $this->glucidesG100g = $glucidesG100g;

        return $this;
    }

    public function getLipidesG100g(): ?string
    {
        return $this->lipidesG100g;
    }

    public function setLipidesG100g(string $lipidesG100g): static
    {
        $this->lipidesG100g = $lipidesG100g;

        return $this;
    }

    public function getFibresG100g(): ?string
    {
        return $this->fibresG100g;
    }

    public function setFibresG100g(string $fibresG100g): static
    {
        $this->fibresG100g = $fibresG100g;

        return $this;
    }

    public function getPoidsTotalG(): ?float
    {
        return $this->poidsTotalG;
    }

    public function setPoidsTotalG(float $poidsTotalG): static
    {
        $this->poidsTotalG = $poidsTotalG;

        return $this;
    }

    public function getKcal100g(): ?string
    {
        return $this->kcal100g;
    }

    public function setKcal100g(string $kcal100g): static
    {
        $this->kcal100g = $kcal100g;

        return $this;
    }

    public function getTempsPreparation(): ?string
    {
        return $this->tempsPreparation;
    }

    public function setTempsPreparation(?string $tempsPreparation): static
    {
        $this->tempsPreparation = $tempsPreparation;

        return $this;
    }

    public function getCycleProduction(): ?int
    {
        return $this->cycleProduction;
    }

    public function setCycleProduction(?int $cycleProduction): static
    {
        $this->cycleProduction = $cycleProduction;

        return $this;
    }

    public function getCategorieRecette(): ?CategorieRecette
    {
        return $this->categorieRecette;
    }

    public function setCategorieRecette(?CategorieRecette $categorieRecette): static
    {
        $this->categorieRecette = $categorieRecette;

        return $this;
    }
}

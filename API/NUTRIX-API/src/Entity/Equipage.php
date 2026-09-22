<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'Equipage')]
#[ApiResource]
class Equipage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'Id_Equipage')]
    private ?int $id = null;

    /**
     * Convention : true = Homme (M), false = Femme (F) — voir MOTEUR_CALCUL.md §1 (ecart #6).
     */
    #[ORM\Column(name: 'sexe')]
    private ?bool $sexe = null;

    #[ORM\Column(name: 'age', type: 'smallint')]
    #[Assert\PositiveOrZero]
    private ?int $age = null;

    #[ORM\Column(name: 'poids_kilo', type: 'float')]
    #[Assert\Positive]
    private ?float $poidsKilo = null;

    #[ORM\Column(name: 'taille_cm', type: 'smallint')]
    #[Assert\Positive]
    private ?int $tailleCm = null;

    #[ORM\Column(name: 'bmi', type: 'float')]
    private ?float $bmi = null;

    #[ORM\Column(name: 'pal', type: 'float')]
    private ?float $pal = null;

    #[ORM\ManyToOne(targetEntity: ActivityLabel::class)]
    #[ORM\JoinColumn(name: 'Id_Activity_label', referencedColumnName: 'Id_Activity_label', nullable: false)]
    #[Assert\NotNull]
    private ?ActivityLabel $activityLabel = null;

    /**
     * Compte de connexion associe a ce profil equipage. Nullable : un profil peut exister
     * sans compte (import initial), et un compte (ex. role FERME/ADMIN) sans profil equipage.
     *
     * readable: false volontairement : on ecrit via IRI (PATCH {"user": "/api/users/5"}), mais
     * on expose la lecture via la propriete userId (int simple) ci-dessous plutot que d'imbriquer
     * l'objet User (qui porte roles/mot de passe) dans les reponses Equipage.
     */
    #[ApiProperty(readable: false)]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'Id_User', referencedColumnName: 'id', nullable: true, unique: true)]
    private ?User $user = null;

    /**
     * Champ "fantome" mappe sur la meme colonne Id_User, en lecture seule (insertable/updatable
     * false) -- expose l'id du compte lie sans exposer l'objet User complet (voir plus haut).
     */
    #[ORM\Column(name: 'Id_User', type: 'integer', nullable: true, insertable: false, updatable: false)]
    private ?int $userId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isSexe(): ?bool
    {
        return $this->sexe;
    }

    public function setSexe(bool $sexe): static
    {
        $this->sexe = $sexe;

        return $this;
    }

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function setAge(int $age): static
    {
        $this->age = $age;

        return $this;
    }

    public function getPoidsKilo(): ?float
    {
        return $this->poidsKilo;
    }

    public function setPoidsKilo(float $poidsKilo): static
    {
        $this->poidsKilo = $poidsKilo;

        return $this;
    }

    public function getTailleCm(): ?int
    {
        return $this->tailleCm;
    }

    public function setTailleCm(int $tailleCm): static
    {
        $this->tailleCm = $tailleCm;

        return $this;
    }

    public function getBmi(): ?float
    {
        return $this->bmi;
    }

    public function setBmi(float $bmi): static
    {
        $this->bmi = $bmi;

        return $this;
    }

    public function getPal(): ?float
    {
        return $this->pal;
    }

    public function setPal(float $pal): static
    {
        $this->pal = $pal;

        return $this;
    }

    public function getActivityLabel(): ?ActivityLabel
    {
        return $this->activityLabel;
    }

    public function setActivityLabel(?ActivityLabel $activityLabel): static
    {
        $this->activityLabel = $activityLabel;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }
}

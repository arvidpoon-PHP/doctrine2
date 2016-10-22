<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\JoinColumnMetadata;

/**
 * Performs strict validation of the mapping schema
 *
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 * @link        www.doctrine-project.com
 * @since       1.0
 * @author      Benjamin Eberlei <kontakt@beberlei.de>
 * @author      Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author      Jonathan Wage <jonwage@gmail.com>
 * @author      Roman Borschel <roman@code-factory.org>
 */
class SchemaValidator
{
    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @param EntityManagerInterface $em
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Checks the internal consistency of all mapping files.
     *
     * There are several checks that can't be done at runtime or are too expensive, which can be verified
     * with this command. For example:
     *
     * 1. Check if a relation with "mappedBy" is actually connected to that specified field.
     * 2. Check if "mappedBy" and "inversedBy" are consistent to each other.
     * 3. Check if "referencedColumnName" attributes are really pointing to primary key columns.
     *
     * @return array
     */
    public function validateMapping()
    {
        $errors = [];
        $cmf = $this->em->getMetadataFactory();
        $classes = $cmf->getAllMetadata();

        foreach ($classes as $class) {
            if ($ce = $this->validateClass($class)) {
                $errors[$class->name] = $ce;
            }
        }

        return $errors;
    }

    /**
     * Validates a single class of the current.
     *
     * @param ClassMetadata $class
     *
     * @return array
     */
    public function validateClass(ClassMetadata $class)
    {
        $ce = [];
        $cmf = $this->em->getMetadataFactory();

        foreach ($class->associationMappings as $fieldName => $assoc) {
            if (!class_exists($assoc['targetEntity']) || $cmf->isTransient($assoc['targetEntity'])) {
                $ce[] = "The target entity '" . $assoc['targetEntity'] . "' specified on " . $class->name . '#' . $fieldName . ' is unknown or not an entity.';

                return $ce;
            }

            if ($assoc['mappedBy'] && $assoc['inversedBy']) {
                $ce[] = "The association " . $class . "#" . $fieldName . " cannot be defined as both inverse and owning.";
            }

            $targetMetadata = $cmf->getMetadataFor($assoc['targetEntity']);

            if (isset($assoc['id']) && $targetMetadata->containsForeignIdentifier) {
                $ce[] = "Cannot map association '" . $class->name. "#". $fieldName ." as identifier, because " .
                        "the target entity '". $targetMetadata->name . "' also maps an association as identifier.";
            }

            if ($assoc['mappedBy']) {
                if ($targetMetadata->hasField($assoc['mappedBy'])) {
                    $ce[] = "The association " . $class->name . "#" . $fieldName . " refers to the owning side ".
                            "field " . $assoc['targetEntity'] . "#" . $assoc['mappedBy'] . " which is not defined as association, but as field.";
                }
                if (!$targetMetadata->hasAssociation($assoc['mappedBy'])) {
                    $ce[] = "The association " . $class->name . "#" . $fieldName . " refers to the owning side ".
                            "field " . $assoc['targetEntity'] . "#" . $assoc['mappedBy'] . " which does not exist.";
                } elseif ($targetMetadata->associationMappings[$assoc['mappedBy']]['inversedBy'] == null) {
                    $ce[] = "The field " . $class->name . "#" . $fieldName . " is on the inverse side of a ".
                            "bi-directional relationship, but the specified mappedBy association on the target-entity ".
                            $assoc['targetEntity'] . "#" . $assoc['mappedBy'] . " does not contain the required ".
                            "'inversedBy=\"" . $fieldName . "\"' attribute.";
                } elseif ($targetMetadata->associationMappings[$assoc['mappedBy']]['inversedBy'] != $fieldName) {
                    $ce[] = "The mappings " . $class->name . "#" . $fieldName . " and " .
                            $assoc['targetEntity'] . "#" . $assoc['mappedBy'] . " are ".
                            "inconsistent with each other.";
                }
            }

            if ($assoc['inversedBy']) {
                if ($targetMetadata->hasField($assoc['inversedBy'])) {
                    $ce[] = "The association " . $class->name . "#" . $fieldName . " refers to the inverse side ".
                            "field " . $assoc['targetEntity'] . "#" . $assoc['inversedBy'] . " which is not defined as association.";
                }

                if (!$targetMetadata->hasAssociation($assoc['inversedBy'])) {
                    $ce[] = "The association " . $class->name . "#" . $fieldName . " refers to the inverse side ".
                            "field " . $assoc['targetEntity'] . "#" . $assoc['inversedBy'] . " which does not exist.";
                } elseif ($targetMetadata->associationMappings[$assoc['inversedBy']]['mappedBy'] == null) {
                    $ce[] = "The field " . $class->name . "#" . $fieldName . " is on the owning side of a ".
                            "bi-directional relationship, but the specified mappedBy association on the target-entity ".
                            $assoc['targetEntity'] . "#" . $assoc['mappedBy'] . " does not contain the required ".
                            "'inversedBy' attribute.";
                } elseif ($targetMetadata->associationMappings[$assoc['inversedBy']]['mappedBy'] != $fieldName) {
                    $ce[] = "The mappings " . $class->name . "#" . $fieldName . " and " .
                            $assoc['targetEntity'] . "#" . $assoc['inversedBy'] . " are ".
                            "inconsistent with each other.";
                }

                // Verify inverse side/owning side match each other
                if (array_key_exists($assoc['inversedBy'], $targetMetadata->associationMappings)) {
                    $targetAssoc = $targetMetadata->associationMappings[$assoc['inversedBy']];
                    if ($assoc['type'] == ClassMetadata::ONE_TO_ONE && $targetAssoc['type'] !== ClassMetadata::ONE_TO_ONE) {
                        $ce[] = "If association " . $class->name . "#" . $fieldName . " is one-to-one, then the inversed " .
                                "side " . $targetMetadata->name . "#" . $assoc['inversedBy'] . " has to be one-to-one as well.";
                    } elseif ($assoc['type'] == ClassMetadata::MANY_TO_ONE && $targetAssoc['type'] !== ClassMetadata::ONE_TO_MANY) {
                        $ce[] = "If association " . $class->name . "#" . $fieldName . " is many-to-one, then the inversed " .
                                "side " . $targetMetadata->name . "#" . $assoc['inversedBy'] . " has to be one-to-many.";
                    } elseif ($assoc['type'] == ClassMetadata::MANY_TO_MANY && $targetAssoc['type'] !== ClassMetadata::MANY_TO_MANY) {
                        $ce[] = "If association " . $class->name . "#" . $fieldName . " is many-to-many, then the inversed " .
                                "side " . $targetMetadata->name . "#" . $assoc['inversedBy'] . " has to be many-to-many as well.";
                    }
                }
            }

            if ($assoc['isOwningSide']) {
                if ($assoc['type'] == ClassMetadata::MANY_TO_MANY) {
                    $classIdentifierColumns  = array_keys($class->getIdentifierColumns($this->em));
                    $targetIdentifierColumns = array_keys($targetMetadata->getIdentifierColumns($this->em));

                    foreach ($assoc['joinTable']->getJoinColumns() as $joinColumn) {
                        if (!in_array($joinColumn->getReferencedColumnName(), $classIdentifierColumns)) {
                            $ce[] = "The referenced column name '" . $joinColumn->getReferencedColumnName() . "' " .
                                "has to be a primary key column on the target entity class '".$class->name."'.";
                            break;
                        }
                    }

                    foreach ($assoc['joinTable']->getInverseJoinColumns() as $inverseJoinColumn) {
                        if (!in_array($inverseJoinColumn->getReferencedColumnName(), $targetIdentifierColumns)) {
                            $ce[] = "The referenced column name '" . $joinColumn->getReferencedColumnName() . "' " .
                                "has to be a primary key column on the target entity class '".$targetMetadata->name."'.";
                            break;
                        }
                    }

                    if (count($targetIdentifierColumns) !== count($assoc['joinTable']->getInverseJoinColumns())) {
                        $columnNames = array_map(
                            function (JoinColumnMetadata $joinColumn) {
                                return $joinColumn->getReferencedColumnName();
                            },
                            $assoc['joinTable']->getInverseJoinColumns()
                        );

                        $ce[] = "The inverse join columns of the many-to-many table '" . $assoc['joinTable']->getName() . "' " .
                                "have to contain to ALL identifier columns of the target entity '". $targetMetadata->name . "', " .
                                "however '" . implode(", ", array_diff($targetIdentifierColumns, $columnNames)) .
                                "' are missing.";
                    }

                    if (count($classIdentifierColumns) !== count($assoc['joinTable']->getJoinColumns())) {
                        $columnNames = array_map(
                            function (JoinColumnMetadata $joinColumn) {
                                return $joinColumn->getReferencedColumnName();
                            },
                            $assoc['joinTable']->getJoinColumns()
                        );

                        $ce[] = "The join columns of the many-to-many table '" . $assoc['joinTable']->getName() . "' " .
                                "have to contain to ALL identifier columns of the source entity '". $class->name . "', " .
                                "however '" . implode(", ", array_diff($classIdentifierColumns, $columnNames)) .
                                "' are missing.";
                    }

                } elseif ($assoc['type'] & ClassMetadata::TO_ONE) {
                    $identifierColumns = array_keys($targetMetadata->getIdentifierColumns($this->em));

                    foreach ($assoc['joinColumns'] as $joinColumn) {
                        if (!in_array($joinColumn->getReferencedColumnName(), $identifierColumns)) {
                            $ce[] = "The referenced column name '" . $joinColumn->getReferencedColumnName() . "' " .
                                    "has to be a primary key column on the target entity class '".$targetMetadata->name."'.";
                        }
                    }

                    if (count($identifierColumns) !== count($assoc['joinColumns'])) {
                        $ids = [];

                        foreach ($assoc['joinColumns'] as $joinColumn) {
                            $ids[] = $joinColumn->getColumnName();
                        }

                        $ce[] = "The join columns of the association '" . $assoc['fieldName'] . "' " .
                                "have to match to ALL identifier columns of the target entity '". $targetMetadata->name . "', " .
                                "however '" . implode(", ", array_diff($identifierColumns, $ids)) .
                                "' are missing.";
                    }
                }
            }

            if (isset($assoc['orderBy']) && $assoc['orderBy'] !== null) {
                foreach ($assoc['orderBy'] as $orderField => $orientation) {
                    if (!$targetMetadata->hasField($orderField) && !$targetMetadata->hasAssociation($orderField)) {
                        $ce[] = "The association " . $class->name."#".$fieldName." is ordered by a foreign field " .
                                $orderField . " that is not a field on the target entity " . $targetMetadata->name . ".";
                        continue;
                    }
                    if ($targetMetadata->isCollectionValuedAssociation($orderField)) {
                        $ce[] = "The association " . $class->name."#".$fieldName." is ordered by a field " .
                                $orderField . " on " . $targetMetadata->name . " that is a collection-valued association.";
                        continue;
                    }
                    if ($targetMetadata->isAssociationInverseSide($orderField)) {
                        $ce[] = "The association " . $class->name."#".$fieldName." is ordered by a field " .
                                $orderField . " on " . $targetMetadata->name . " that is the inverse side of an association.";
                        continue;
                    }
                }
            }
        }

        foreach ($class->subClasses as $subClass) {
            if (!in_array($class->name, class_parents($subClass))) {
                $ce[] = "According to the discriminator map class '" . $subClass . "' has to be a child ".
                        "of '" . $class->name . "' but these entities are not related through inheritance.";
            }
        }

        return $ce;
    }

    /**
     * Checks if the Database Schema is in sync with the current metadata state.
     *
     * @return bool
     */
    public function schemaInSyncWithMetadata()
    {
        $schemaTool = new SchemaTool($this->em);
        $allMetadata = $this->em->getMetadataFactory()->getAllMetadata();

        return count($schemaTool->getUpdateSchemaSql($allMetadata, true)) == 0;
    }
}

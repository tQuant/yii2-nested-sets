<?php
/**
 * @link https://github.com/tquant/yii2-nested-sets
 * @copyright Copyright (c) 2015 Mikhail Meschangin
 * @license http://opensource.org/licenses/BSD-3-Clause
 */

namespace creocoder\nestedsets;

use yii\db\ActiveRecord;

/**
 * This is the trait class for nested sets
 *
 * @property $this[] $children
 * @property $this[] $parents
 * @property $this $parent
 * @property $this $next
 * @property $this $prev
 */
trait NestedActiveRecordTrait {
    /** @var array список загруженных деревьев */
    static protected $_trees = [];
    /** @var array Кешированный список связей */
    protected $_nested = [];

    /**
     * Загружает полное дерево и возвращает его корень
     *
     * @param mixed|ActiveRecord $treeId ID дерева
     * @param array $with
     * @return null|ActiveRecord[] Корень дерева
     */
    public static function loadTree($treeId, $with = []) {
        if ($treeId instanceof ActiveRecord) {
            $treeObject = $treeId;
            $treeId = $treeObject->getPrimaryKey();
        }
        if (!array_key_exists($treeId, static::$_trees)) {
            $model = new static;
            $all = static::find()->with($with)->where([$model->treeAttribute => $treeId])->orderBy($model->leftAttribute)->all();
            if (empty($all)) {
                static::$_trees[$treeId] = [];
            } else {
                /** @var ActiveRecord $root */
                $root = $all[0];
                $root->_nested['parents'] = [];
                $root->_nested['parent'] = null;
                $root->_nested['prev'] = null;
                $root->_nested['next'] = null;
                if (isset($treeObject) && isset($root->treeRelation)) {
                    $root->populateRelation($root->treeRelation, $treeObject);
                }
                static::_buildTreeLevel(array_slice($all, 1), $root, $model->leftAttribute, $model->rightAttribute, true);
                static::$_trees[$treeId] = $all;
            }
        }

        return static::$_trees[$treeId];
    }

    /**
     * Строит связи между элементами дерева
     *
     * @param ActiveRecord[] $list
     * @param ActiveRecord $root
     * @param string $leftAttribute
     * @param string $rightAttribute
     * @param boolean $fullTree
     */
    protected static function _buildTreeLevel($list, $root, $leftAttribute, $rightAttribute, $fullTree) {
        $prev = null;
        $root->_nested['children'] = [];
        for ($i = 0; $i < count($list); ++$i) {
            $item = $list[$i];
            // Устнавливаем связи
            $root->_nested['children'][] = $item;
            $item->_nested['parent'] = $root;
            $item->_nested['prev'] = $prev;
            if ($prev !== null) {
                $prev->_nested['next'] = $item;
            }
            $prev = $item;
            // Устанавливаем связь с древообразующим объектом
            if (isset($root->treeRelation)) {
                $item->populateRelation($root->treeRelation, $root->{$root->treeRelation});
            }

            if ($fullTree) {
                // Цепочка родительских элементов
                $item->_nested['parents'] = array_merge($root->_nested['parents'], [$root]);
                // Если есть дочерние элементы
                $children = ($item->getAttribute($rightAttribute) - $item->getAttribute($leftAttribute) - 1) / 2;
                if ($children > 0) {
                    static::_buildTreeLevel(array_slice($list, $i + 1, $children), $item, $leftAttribute, $rightAttribute, $fullTree);
                    $i += $children;
                } else {
                    $item->_nested['children'] = [];
                }
            }
        }
        // Следующий элемент последнего элемента = null
        if ($prev !== null) {
            $prev->_nested['next'] = null;
        }
    }

    /**
     * @return static[]
     */
    public function getParents() {
        if (!array_key_exists('parents', $this->_nested)) {
            if ($this->getAttribute($this->leftAttribute) == 1) {
                $this->_nested['parents'] = [];
                $this->_nested['parent'] = null;
            } else {
                $this->_nested['parents'] = $this->parents()->all();

                if (empty($this->_nested['parents'])) {
                    $this->_nested['parent'] = null;
                } else {
                    $parents = array_reverse($this->_nested['parents']);
                    $child = $this;
                    foreach ($parents as $parent) {
                        $child->_nested['parent'] = $parent;
                        $child = $parent;
                        // Устанавливаем связь с древообразующим объектом
                        if (isset($this->treeRelation)) {
                            $parent->populateRelation($this->treeRelation, $this->{$this->treeRelation});
                        }
                    }
                    if (isset($parent)) {
                        $parent->_nested['parent'] = null;
                    }
                }
            }
        }

        return $this->_nested['parents'];
    }

    /**
     * @return static
     */
    public function getParent() {
        if (!array_key_exists('parent', $this->_nested)) {
            $this->getParents();
        }

        return $this->_nested['parent'];
    }

    /**
     * @param array $with
     * @return static[]
     */
    public function getChildren($with = []) {
        if (!array_key_exists('children', $this->_nested)) {
            if ($this->getAttribute($this->rightAttribute) - $this->getAttribute($this->leftAttribute) == 1) {
                $this->_nested['children'] = [];
            } else {
                $list = $this->children(1)->with($with)->all();
                static::_buildTreeLevel($list, $this, $this->leftAttribute, $this->rightAttribute, false);
            }
        }

        return $this->_nested['children'];
    }

    /**
     * @return static
     */
    public function getPrev() {
        if (!array_key_exists('prev', $this->_nested)) {
            $this->_nested['prev'] = $this->prev()->one();

            // Устанавливаем связь с древообразующим объектом
            if ($this->_nested['prev'] !== null && isset($this->treeRelation)) {
                $this->_nested['prev']->populateRelation($this->treeRelation, $this->{$this->treeRelation});
            }
        }

        return $this->_nested['prev'];
    }

    /**
     * @return static
     */
    public function getNext() {
        if (!array_key_exists('next', $this->_nested)) {
            $this->_nested['next'] = $this->next()->one();

            // Устанавливаем связь с древообразующим объектом
            if ($this->_nested['next'] !== null && isset($this->treeRelation)) {
                $this->_nested['next']->populateRelation($this->treeRelation, $this->{$this->treeRelation});
            }
        }

        return $this->_nested['next'];
    }
}

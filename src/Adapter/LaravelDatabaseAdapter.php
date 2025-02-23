<?php

/**
 * @desc  Policy Adapter
 * @author Tinywan(ShaoBo Wan)
 * @date 2022/01/12 10:37
 */

declare(strict_types=1);

namespace Casbin\WebmanPermission\Adapter;

use Casbin\Model\Model;
use Casbin\Persist\Adapter;
use Casbin\Persist\AdapterHelper;
use Casbin\Persist\UpdatableAdapter;
use Casbin\Persist\BatchAdapter;
use Casbin\Persist\FilteredAdapter;
use Casbin\Persist\Adapters\Filter;
use Casbin\Exceptions\InvalidFilterTypeException;
use Casbin\WebmanPermission\Model\LaravelRuleModel;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DatabaseAdapter.
 *
 * @author techlee@qq.com
 */
class LaravelDatabaseAdapter implements Adapter, UpdatableAdapter, BatchAdapter, FilteredAdapter
{
    use AdapterHelper;

    /**
     * @var bool
     */
    private bool $filtered = false;

    /**
     * RuleModel model.
     *
     * @var LaravelRuleModel
     */
    protected $model;

    /**
     * LaravelDatabaseAdapter constructor.
     * @param LaravelRuleModel $model
     */
    public function __construct(LaravelRuleModel $model)
    {
        $this->model = $model;
    }

    /**
     * Filter the rule.
     *
     * @param array $rule
     * @return array
     */
    public function filterRule(array $rule): array
    {
        $rule = array_values($rule);

        $i = count($rule) - 1;
        for (; $i >= 0; $i--) {
            if ($rule[$i] != '' && !is_null($rule[$i])) {
                break;
            }
        }

        return array_slice($rule, 0, $i + 1);
    }

    /**
     * savePolicyLine function.
     *
     * @param string $ptype
     * @param array  $rule
     *
     * @return void
     */
    public function savePolicyLine(string $ptype, array $rule)
    {
        $col['ptype'] = $ptype;
        foreach ($rule as $key => $value) {
            $col['v' . strval($key) . ''] = $value;
        }
        $this->model->create($col);
    }

    /**
     * loads all policy rules from the storage.
     *
     * @param Model $model
     */
    public function loadPolicy(Model $model): void
    {
        $rows = $this->model->getAllFromCache();
        foreach ($rows as $row) {
            $this->loadPolicyArray($this->filterRule($row), $model);
        }
    }

    /**
     * saves all policy rules to the storage.
     *
     * @param Model $model
     */
    public function savePolicy(Model $model): void
    {
        foreach ($model['p'] as $ptype => $ast) {
            foreach ($ast->policy as $rule) {
                $this->savePolicyLine($ptype, $rule);
            }
        }

        foreach ($model['g'] as $ptype => $ast) {
            foreach ($ast->policy as $rule) {
                $this->savePolicyLine($ptype, $rule);
            }
        }
    }

    /**
     * adds a policy rule to the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param array  $rule
     */
    public function addPolicy(string $sec, string $ptype, array $rule): void
    {
        $this->savePolicyLine($ptype, $rule);
    }

    /**
     * Adds a policy rules to the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[][] $rules
     */
    public function addPolicies(string $sec, string $ptype, array $rules): void
    {
        $cols = [];
        $i = 0;

        foreach ($rules as $rule) {
            $temp['ptype'] = $ptype;
            $temp['created_at'] = date("Y-m-d h:m:i");
            $temp['updated_at'] = $temp['created_at'];
            foreach ($rule as $key => $value) {
                $temp['v' . strval($key)] = $value;
            }
            $cols[$i++] = $temp ?? [];
            $temp = [];
        }
        $this->model->insert($cols);
        LaravelRuleModel::fireModelEvent('saved');
    }

    /**
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param array  $rule
     */
    public function removePolicy(string $sec, string $ptype, array $rule): void
    {
        $instance = $this->model->where('ptype', $ptype);
        foreach ($rule as $key => $value) {
            $instance->where('v' . strval($key), $value);
        }
        $instance->delete();
        LaravelRuleModel::fireModelEvent('deleted');
    }

    /**
     * @param string      $sec
     * @param string      $ptype
     * @param int         $fieldIndex
     * @param string|null ...$fieldValues
     * @return array
     * @throws Throwable
     */
    public function _removeFilteredPolicy(string $sec, string $ptype, int $fieldIndex, ?string ...$fieldValues): array
    {
        $count = 0;
        $removedRules = [];

        $instance = $this->model->where('ptype', $ptype);
        foreach (range(0, 5) as $value) {
            if ($fieldIndex <= $value && $value < $fieldIndex + count($fieldValues)) {
                if ('' != $fieldValues[$value - $fieldIndex]) {
                    $instance->where('v' . strval($value), $fieldValues[$value - $fieldIndex]);
                }
            }
        }

        foreach ($instance->select() as $model) {
            $item = $model->hidden(['id', 'ptype'])->toArray();
            $item = $this->filterRule($item);
            $removedRules[] = $item;
            if ($model->cache('tauthz')->delete()) {
                ++$count;
            }
        }

        return $removedRules;
    }

    /**
     * Removes policy rules from the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[][] $rules
     */
    public function removePolicies(string $sec, string $ptype, array $rules): void
    {
        DB::transaction(function () use ($sec, $ptype, $rules) {
            foreach ($rules as $rule) {
                $this->removePolicy($sec, $ptype, $rule);
            }
        });
    }

    /**
     * RemoveFilteredPolicy removes policy rules that match the filter from the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param int    $fieldIndex
     * @param string ...$fieldValues
     */
    public function removeFilteredPolicy(string $sec, string $ptype, int $fieldIndex, string ...$fieldValues): void
    {
        $instance = $this->model->where('ptype', $ptype);
        foreach (range(0, 5) as $value) {
            if ($fieldIndex <= $value && $value < $fieldIndex + count($fieldValues)) {
                if ('' != $fieldValues[$value - $fieldIndex]) {
                    $instance->where('v' . strval($value), $fieldValues[$value - $fieldIndex]);
                }
            }
        }

        $oldP = $instance->get()->makeHidden(['created_at', 'updated_at', 'id', 'ptype'])->toArray();
        foreach ($oldP as &$item) {
            $item = $this->filterRule($item);
            $removedRules[] = $item;
        }
        $instance->delete();
        LaravelRuleModel::fireModelEvent('deleted');
    }

    /**
     * Updates a policy rule from storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[] $oldRule
     * @param string[] $newPolicy
     */
    public function updatePolicy(string $sec, string $ptype, array $oldRule, array $newPolicy): void
    {
        $instance = $this->model->where('ptype', $ptype);
        foreach ($oldRule as $key => $value) {
            $instance->where('v' . strval($key), $value);
        }
        $instance = $instance->first();

        $update = [];
        foreach ($newPolicy as $key => $value) {
            $update['v' . $key] = $value;
        }

        $instance->update($update);
        LaravelRuleModel::fireModelEvent('saved');
    }

    /**
     * UpdatePolicies updates some policy rules to storage, like DB, redis.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[][] $oldRules
     * @param string[][] $newRules
     * @return void
     */
    public function updatePolicies(string $sec, string $ptype, array $oldRules, array $newRules): void
    {
        DB::transaction(function () use ($sec, $ptype, $oldRules, $newRules) {
            foreach ($oldRules as $i => $oldRule) {
                $this->updatePolicy($sec, $ptype, $oldRule, $newRules[$i]);
            }
        });
    }

    /**
     * UpdateFilteredPolicies deletes old rules and adds new rules.
     *
     * @param string $sec
     * @param string $ptype
     * @param array $newPolicies
     * @param integer $fieldIndex
     * @param string ...$fieldValues
     * @return array
     */
    public function updateFilteredPolicies(string $sec, string $ptype, array $newPolicies, int $fieldIndex, string ...$fieldValues): array
    {
        $oldRules = [];
        \Illuminate\Support\Facades\DB::transaction(function () use ($sec, $ptype, $fieldIndex, $fieldValues, $newPolicies, &$oldRules) {
            $oldRules = $this->_removeFilteredPolicy($sec, $ptype, $fieldIndex, ...$fieldValues);
            $this->addPolicies($sec, $ptype, $newPolicies);
        });
        return $oldRules;
    }

    /**
     * Returns true if the loaded policy has been filtered.
     *
     * @return bool
     */
    public function isFiltered(): bool
    {
        return $this->filtered;
    }

    /**
     * Sets filtered parameter.
     *
     * @param bool $filtered
     */
    public function setFiltered(bool $filtered): void
    {
        $this->filtered = $filtered;
    }

    /**
     * Loads only policy rules that match the filter.
     *
     * @param Model $model
     * @param mixed $filter
     */
    public function loadFilteredPolicy(Model $model, $filter): void
    {
        $instance = $this->model;
        if (is_string($filter)) {
            $instance = $instance->whereRaw($filter);
        } elseif ($filter instanceof Filter) {
            foreach ($filter->p as $k => $v) {
                $where[$v] = $filter->g[$k];
                $instance = $instance->where($v, $filter->g[$k]);
            }
        } elseif ($filter instanceof \Closure) {
            $instance = $instance->where($filter);
        } else {
            throw new InvalidFilterTypeException('invalid filter type');
        }
        $rows = $instance->get()->makeHidden(['created_at', 'updated_at', 'id'])->toArray();
        if ($rows) {
            foreach ($rows as $row) {
                $row = array_filter($row, function ($value) {
                    return !is_null($value) && $value !== '';
                });
                $line = implode(', ', array_filter($row, function ($val) {
                    return '' != $val && !is_null($val);
                }));
                $this->loadPolicyLine(trim($line), $model);
            }
        }
        $this->setFiltered(true);
    }
}

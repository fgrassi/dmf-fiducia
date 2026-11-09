<?php

namespace App\Filament\Support;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

class AlphabeticalNavigationManager extends NavigationManager
{
    public function get(): array
    {
        return array_map(function (NavigationGroup $group): NavigationGroup {
            return $group->items($this->sortItems($group->getItems()));
        }, parent::get());
    }

    /**
     * @param  iterable<NavigationItem>  $items
     * @return array<NavigationItem>
     */
    protected function sortItems(iterable $items): array
    {
        $items = collect($items)
            ->each(fn (NavigationItem $item) => $item->childItems($this->sortItems($item->getChildItems())))
            ->sortBy(fn (NavigationItem $item): string => $this->key($item))
            ->values()
            ->all();

        return $items;
    }

    protected function key(NavigationItem $item): string
    {
        $label = $item->getLabel();

        return Str::lower(Str::ascii($label instanceof Htmlable ? $label->toHtml() : $label));
    }
}

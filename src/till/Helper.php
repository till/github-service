<?php
namespace till;

class Helper
{
    protected function filterByType(\stdClass $repository, $type)
    {
        if ($type == 'public') {
            if (false === $repository->private) {
                return true;
            }
            return false;
        }
        if ($type == 'private') {
            if (true === $repository->private) {
                return true;
            }
            return false;
        }
        throw new \InvalidArgumentException("Unknown type '{$type}'.");
    }

    public function findPrivateRepositories(\stdClass $repository)
    {
        return $this->filterByType($repository, 'private');
    }

    public function findPublicRepositories(\stdClass $repository)
    {
        return $this->filterByType($repository, 'public');
    }
}

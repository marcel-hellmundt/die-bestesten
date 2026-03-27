<?php

class CountryController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'admin'];

	protected function get(): mixed
	{
		if ($this->id) {
			$country = $this->db->getCountryById($this->id);
			if (!$country) {
				http_response_code(404);
				return ['status' => false, 'message' => 'Country not found'];
			}
			return $country;
		}

		return $this->db->getCountryList();
	}

	protected function post(): mixed
	{
		if ($this->id !== 'migrate') return $this->methodNotAllowed();
		return $this->db->migrateCountry();
	}
	protected function patch(): mixed
	{
		return $this->methodNotAllowed();
	}
	protected function delete(): mixed
	{
		return $this->methodNotAllowed();
	}
}

<?php

namespace Modules\ModuleAmoCrm\Lib;

/**
 * Экспоненциальная пауза между попытками обращения к недоступному сервису
 * и ограничение частоты записи ошибок в лог (#57).
 *
 * Состояние общее для сервиса, а не для отдельного звонка: отказ означает
 * недоступность ConnectorDb, а не проблему конкретных данных.
 */
final class RetryBackoff
{
    private int $baseDelay;
    private int $maxDelay;
    private int $logInterval;

    private int $failures = 0;
    private int $retryAt = 0;
    private int $firstFailureAt = 0;
    private int $lastLoggedAt = 0;

    public function __construct(int $baseDelay = 2, int $maxDelay = 60, int $logInterval = 60)
    {
        $this->baseDelay   = max(1, $baseDelay);
        $this->maxDelay    = max($this->baseDelay, $maxDelay);
        $this->logInterval = max(0, $logInterval);
    }

    /**
     * Можно ли выполнять попытку сейчас.
     */
    public function canAttempt(int $now): bool
    {
        return $now >= $this->retryAt;
    }

    /**
     * Регистрирует неудачу и назначает следующую попытку.
     * @return bool true — ошибку нужно записать в лог (первая неудача
     *              или прошло не меньше logInterval с прошлой записи).
     */
    public function fail(int $now): bool
    {
        $this->failures++;
        if ($this->failures === 1) {
            $this->firstFailureAt = $now;
        }
        $this->retryAt = $now + $this->delay();

        if ($this->failures === 1 || $now - $this->lastLoggedAt >= $this->logInterval) {
            $this->lastLoggedAt = $now;
            return true;
        }
        return false;
    }

    /**
     * Регистрирует успех и сбрасывает состояние.
     * @return int|null Длительность отказа в секундах, если до этого были неудачи.
     */
    public function succeed(int $now): ?int
    {
        if ($this->failures === 0) {
            return null;
        }
        $outage = $now - $this->firstFailureAt;
        $this->failures       = 0;
        $this->retryAt        = 0;
        $this->firstFailureAt = 0;
        $this->lastLoggedAt   = 0;
        return $outage;
    }

    public function failures(): int
    {
        return $this->failures;
    }

    public function retryAt(): int
    {
        return $this->retryAt;
    }

    /**
     * Текущая пауза: base * 2^(failures-1), не больше maxDelay.
     */
    private function delay(): int
    {
        $exp = min($this->failures - 1, 30);
        return (int)min($this->maxDelay, $this->baseDelay * (2 ** $exp));
    }
}

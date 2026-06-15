package ols

import "testing"

type fakeRunner struct {
	results []CommandResult
	calls   [][]string
}

func (r *fakeRunner) Run(name string, args ...string) CommandResult {
	call := append([]string{name}, args...)
	r.calls = append(r.calls, call)
	if len(r.results) == 0 {
		return CommandResult{ExitCode: 1}
	}
	result := r.results[0]
	r.results = r.results[1:]
	return result
}

func TestValidateAllowsWarningExitCode(t *testing.T) {
	runner := &fakeRunner{
		results: []CommandResult{
			{ExitCode: 1, Output: "[WARN] example vhost uses server uid"},
		},
	}
	if err := New(runner).Validate(); err != nil {
		t.Fatalf("warning-only validation must pass: %v", err)
	}
}

func TestValidateRejectsErrorOutput(t *testing.T) {
	runner := &fakeRunner{
		results: []CommandResult{
			{ExitCode: 1, Output: "[ERROR] invalid listener"},
		},
	}
	if err := New(runner).Validate(); err == nil {
		t.Fatal("error output must fail validation")
	}
}

func TestReloadUsesLswsControl(t *testing.T) {
	runner := &fakeRunner{
		results: []CommandResult{
			{ExitCode: 0},
		},
	}
	manager := New(runner)
	if err := manager.Reload(); err != nil {
		t.Fatalf("reload failed: %v", err)
	}
	if len(runner.calls) != 1 ||
		len(runner.calls[0]) != 2 ||
		runner.calls[0][0] != defaultControl ||
		runner.calls[0][1] != "reload" {
		t.Fatalf("unexpected command: %+v", runner.calls)
	}
}

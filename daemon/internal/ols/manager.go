package ols

import (
	"fmt"
	"os/exec"
	"strings"
)

const (
	defaultBinary  = "/usr/local/lsws/bin/openlitespeed"
	defaultControl = "/usr/local/lsws/bin/lswsctrl"
)

type CommandResult struct {
	ExitCode int
	Output   string
}

type Runner interface {
	Run(name string, args ...string) CommandResult
}

type ExecRunner struct{}

func (ExecRunner) Run(name string, args ...string) CommandResult {
	cmd := exec.Command(name, args...)
	out, err := cmd.CombinedOutput()
	exitCode := 0
	if err != nil {
		exitCode = 1
		if exitErr, ok := err.(*exec.ExitError); ok {
			exitCode = exitErr.ExitCode()
		}
	}
	return CommandResult{
		ExitCode: exitCode,
		Output:   strings.TrimSpace(string(out)),
	}
}

type Manager struct {
	runner  Runner
	binary  string
	control string
}

func New(runner Runner) *Manager {
	if runner == nil {
		runner = ExecRunner{}
	}
	return &Manager{
		runner:  runner,
		binary:  defaultBinary,
		control: defaultControl,
	}
}

func (m *Manager) Validate() error {
	result := m.runner.Run(m.binary, "-t")
	if result.ExitCode >= 2 ||
		strings.Contains(strings.ToLower(result.Output), "[error]") ||
		strings.Contains(strings.ToLower(result.Output), "fatal error") {
		return fmt.Errorf(
			"OLS configuration validation failed with exit code %d: %s",
			result.ExitCode,
			result.Output,
		)
	}
	return nil
}

func (m *Manager) Reload() error {
	result := m.runner.Run(m.control, "reload")
	if result.ExitCode != 0 {
		return fmt.Errorf(
			"OLS reload failed with exit code %d: %s",
			result.ExitCode,
			result.Output,
		)
	}
	return nil
}

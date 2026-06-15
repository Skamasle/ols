package htaccesswatch

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/fsnotify/fsnotify"
)

const (
	httpdocsDir  = "httpdocs"
	htaccessFile = ".htaccess"
)

type Event struct {
	Key       string
	Domain    string
	VhostRoot string
	Path      string
	Op        fsnotify.Op
	When      time.Time
}

type Watcher struct {
	root    string
	watcher *fsnotify.Watcher

	events chan Event
	errors chan error

	mu     sync.Mutex
	paths  map[string]struct{}
	closed bool
	wg     sync.WaitGroup
}

func New(root string) (*Watcher, error) {
	cleanRoot := filepath.Clean(root)
	if "" == cleanRoot {
		return nil, fmt.Errorf("watch root is required")
	}

	fw, err := fsnotify.NewWatcher()
	if err != nil {
		return nil, err
	}

	w := &Watcher{
		root:    cleanRoot,
		watcher: fw,
		events:  make(chan Event, 256),
		errors:  make(chan error, 32),
		paths:   make(map[string]struct{}),
	}

	w.wg.Add(1)
	go func() {
		defer w.wg.Done()
		w.forward()
	}()

	return w, nil
}

func (w *Watcher) Close() error {
	w.mu.Lock()
	if w.closed {
		w.mu.Unlock()
		return nil
	}
	w.closed = true
	err := w.watcher.Close()
	w.mu.Unlock()

	w.wg.Wait()
	close(w.events)
	close(w.errors)
	return err
}

func (w *Watcher) Events() <-chan Event {
	return w.events
}

func (w *Watcher) Errors() <-chan error {
	return w.errors
}

func (w *Watcher) Rescan() error {
	return filepath.Walk(w.root, func(path string, info os.FileInfo, err error) error {
		if err != nil || info == nil {
			return nil
		}
		if !info.IsDir() || !w.isWatchedDir(path) {
			return nil
		}
		return w.addDir(path)
	})
}

func (w *Watcher) forward() {
	for {
		select {
		case event, ok := <-w.watcher.Events:
			if !ok {
				return
			}
			w.handle(event)
		case err, ok := <-w.watcher.Errors:
			if !ok {
				return
			}
			w.emitError(err)
		}
	}
}

func (w *Watcher) handle(event fsnotify.Event) {
	if hasOp(event, fsnotify.Create) {
		if info, err := os.Stat(event.Name); err == nil && info.IsDir() && w.isWatchedDir(event.Name) {
			if err := w.addDir(event.Name); err != nil {
				w.emitError(err)
			}
		}
	}

	if filepath.Base(event.Name) != htaccessFile {
		return
	}
	if !hasOp(event, fsnotify.Create) &&
		!hasOp(event, fsnotify.Write) &&
		!hasOp(event, fsnotify.Remove) &&
		!hasOp(event, fsnotify.Rename) {
		return
	}

	vhostRoot, domain := w.vhostRoot(event.Name)
	w.emit(Event{
		Key:       vhostRoot,
		Domain:    domain,
		VhostRoot: vhostRoot,
		Path:      event.Name,
		Op:        event.Op,
		When:      time.Now().UTC(),
	})
}

func hasOp(event fsnotify.Event, op fsnotify.Op) bool {
	return event.Op&op != 0
}

func (w *Watcher) addDir(path string) error {
	clean := filepath.Clean(path)
	w.mu.Lock()
	defer w.mu.Unlock()

	if w.closed {
		return nil
	}
	if _, ok := w.paths[clean]; ok {
		return nil
	}
	if err := w.watcher.Add(clean); err != nil {
		return err
	}
	w.paths[clean] = struct{}{}
	return nil
}

func (w *Watcher) emit(event Event) {
	w.mu.Lock()
	defer w.mu.Unlock()
	closed := w.closed
	if closed {
		return
	}
	select {
	case w.events <- event:
	default:
		return
	}
}

func (w *Watcher) emitError(err error) {
	w.mu.Lock()
	defer w.mu.Unlock()
	closed := w.closed
	if closed {
		return
	}
	select {
	case w.errors <- err:
	default:
		return
	}
}

func (w *Watcher) isWatchedDir(path string) bool {
	clean := filepath.Clean(path)
	if clean == w.root {
		return true
	}
	relative, err := filepath.Rel(w.root, clean)
	if err != nil || strings.HasPrefix(relative, "..") {
		return false
	}
	parts := strings.Split(relative, string(os.PathSeparator))
	if len(parts) == 1 {
		return true
	}
	for _, part := range parts {
		if part == httpdocsDir {
			return true
		}
	}
	return false
}

func (w *Watcher) vhostRoot(path string) (string, string) {
	parent := filepath.Clean(filepath.Dir(path))
	needle := string(os.PathSeparator) + httpdocsDir
	idx := strings.LastIndex(parent, needle)
	if idx == -1 {
		return parent, filepath.Base(parent)
	}

	root := parent[:idx]
	if "" == root {
		root = string(os.PathSeparator)
	}
	return root, filepath.Base(root)
}
